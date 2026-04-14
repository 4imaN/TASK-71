<?php

namespace App\Services\Import;

use App\Models\ImportConflict;
use App\Models\ImportJob;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Import\EntityStrategies\DepartmentStrategy;
use App\Services\Import\EntityStrategies\ResearchProjectStrategy;
use App\Services\Import\EntityStrategies\ServiceStrategy;
use App\Services\Import\EntityStrategies\UserAccountStrategy;
use App\Services\Import\EntityStrategies\UserProfileStrategy;

class ImportProcessorService
{
    public function __construct(
        private readonly ImportParserService $parser,
        private readonly ConflictResolutionService $conflictResolver,
        private readonly AuditLogger $auditLogger,
        private readonly ServiceStrategy $serviceStrategy,
    ) {}

    /**
     * Process a full import job synchronously.
     *
     * @param ImportJob $job
     * @param string $rawContent Raw file content to parse
     * @param User $admin Acting administrator
     */
    public function process(ImportJob $job, string $rawContent, User $admin): ImportJob
    {
        $job->update(['status' => 'processing']);

        $this->auditLogger->log(
            action: 'import.job_started',
            actorId: $admin->id,
            entityType: 'import_job',
            entityId: $job->id,
            afterState: $job->fresh()->toArray(),
        );

        try {
            $strategy = $this->resolveStrategy($job->entity_type);

            // Parse the content
            $parsed = $this->parser->parse(
                content: $rawContent,
                format: $job->file_format,
                fieldMapping: $job->field_mapping ?? [],
                lastSyncTimestamp: $job->last_sync_timestamp,
            );

            $rows         = $parsed['rows'];
            $totalRecords = $parsed['total'];
            $processed    = 0;
            $failed       = 0;
            $conflicts     = 0;
            $errors        = [];

            $job->update(['total_records' => $totalRecords]);

            foreach ($rows as $index => $row) {
                // Inject admin reference for ServiceStrategy
                $row['_admin'] = $admin;

                try {
                    // Validate required fields
                    $missingFields = $this->validateRequiredFields($row, $strategy->requiredFields());
                    if (!empty($missingFields)) {
                        $failed++;
                        $errors[] = "Row {$index}: missing required fields: " . implode(', ', $missingFields);
                        continue;
                    }

                    // Find existing record
                    $existing = $strategy->findExisting($row);

                    if ($existing === null) {
                        // No duplicate — apply directly
                        $strategy->apply($row, null);
                        $processed++;
                    } else {
                        // Duplicate found — compute diffs
                        $diffs = $strategy->computeFieldDiffs($row, $existing);

                        if (empty($diffs)) {
                            // No differences, skip silently
                            $processed++;
                            continue;
                        }

                        // Resolve via conflict strategy
                        $result = $this->conflictResolver->resolve(
                            strategy: $job->conflict_resolution_strategy,
                            row: $row,
                            existing: $existing,
                            fieldDiffs: $diffs,
                            job: $job,
                        );

                        if ($result['action'] === 'apply') {
                            $strategy->apply($result['resolvedRow'], $existing);
                            $processed++;
                        } elseif ($result['action'] === 'skip') {
                            // Skipped — existing is newer; count as processed
                            $processed++;
                        } elseif ($result['action'] === 'conflict') {
                            $conflicts++;
                        }
                    }
                } catch (\RuntimeException $e) {
                    $failed++;
                    $errors[] = "Row {$index}: " . $e->getMessage();
                } catch (\Throwable $e) {
                    $failed++;
                    $errors[] = "Row {$index}: unexpected error: " . $e->getMessage();
                }
            }

            // Determine final status
            $unresolvedConflicts = ImportConflict::where('import_job_id', $job->id)
                ->where('resolution', 'pending')
                ->count();

            $finalStatus = $unresolvedConflicts > 0 ? 'needs_review' : 'completed';

            $job->update([
                'status'          => $finalStatus,
                'processed_count' => $processed,
                'error_count'     => $failed,
                'conflict_count'  => $conflicts,
                'error_summary'   => $errors ? implode("\n", $errors) : null,
                'completed_at'    => now(),
            ]);

            $auditAction = $finalStatus === 'needs_review'
                ? 'import.job_needs_review'
                : 'import.job_completed';

            $this->auditLogger->log(
                action: $auditAction,
                actorId: $admin->id,
                entityType: 'import_job',
                entityId: $job->id,
                afterState: $job->fresh()->toArray(),
            );
        } catch (\Throwable $e) {
            $job->update([
                'status'        => 'failed',
                'error_summary' => $e->getMessage(),
                'completed_at'  => now(),
            ]);

            $this->auditLogger->log(
                action: 'import.job_failed',
                actorId: $admin->id,
                entityType: 'import_job',
                entityId: $job->id,
                afterState: ['error' => $e->getMessage()],
            );
        }

        return $job->refresh();
    }

    /**
     * Reprocess all resolved conflicts for a job.
     * For each conflict that is no longer pending, apply the resolved_record via the strategy.
     */
    public function reprocessResolvedConflicts(ImportJob $job, User $admin): void
    {
        $strategy = $this->resolveStrategy($job->entity_type);

        $resolvedConflicts = ImportConflict::where('import_job_id', $job->id)
            ->where('resolution', '!=', 'pending')
            ->whereNotNull('resolved_record')
            ->get();

        foreach ($resolvedConflicts as $conflict) {
            try {
                $row      = array_merge($conflict->resolved_record ?? [], ['_admin' => $admin]);
                $existing = $strategy->findExisting($row);
                $strategy->apply($row, $existing);
            } catch (\Throwable $e) {
                // Log but continue processing remaining conflicts
                $this->auditLogger->log(
                    action: 'import.conflict_reprocess_error',
                    actorId: $admin->id,
                    entityType: 'import_conflict',
                    entityId: $conflict->id,
                    afterState: ['error' => $e->getMessage()],
                );
            }
        }

        // Update job status after reprocessing
        $remainingPending = ImportConflict::where('import_job_id', $job->id)
            ->where('resolution', 'pending')
            ->count();

        if ($remainingPending === 0) {
            $job->update(['status' => 'completed', 'completed_at' => now()]);

            $this->auditLogger->log(
                action: 'import.job_completed',
                actorId: $admin->id,
                entityType: 'import_job',
                entityId: $job->id,
                afterState: $job->fresh()->toArray(),
            );
        }
    }

    /**
     * Resolve the entity strategy for a given entity_type string.
     */
    public function resolveStrategy(string $entityType): EntityStrategyInterface
    {
        return match ($entityType) {
            'departments'       => app(DepartmentStrategy::class),
            'users'             => app(UserAccountStrategy::class),
            'user_profiles'     => app(UserProfileStrategy::class),
            'research_projects' => app(ResearchProjectStrategy::class),
            'services'          => $this->serviceStrategy,
            default             => throw new \InvalidArgumentException("Unknown entity type: {$entityType}"),
        };
    }

    /**
     * Validate that all required fields are present and non-empty in the row.
     * Returns list of missing field names.
     */
    private function validateRequiredFields(array $row, array $requiredFields): array
    {
        $missing = [];
        foreach ($requiredFields as $field) {
            if (!isset($row[$field]) || $row[$field] === '' || $row[$field] === null) {
                $missing[] = $field;
            }
        }
        return $missing;
    }
}
