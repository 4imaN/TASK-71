<?php

namespace App\Services\Import;

use App\Models\ImportConflict;
use App\Models\ImportJob;
use App\Models\User;
use App\Services\Audit\SensitiveDataRedactor;
use Illuminate\Database\Eloquent\Model;

class ConflictResolutionService
{
    public function __construct(
        private readonly SensitiveDataRedactor $redactor,
    ) {}
    /**
     * Decide what to do when a duplicate record is found.
     *
     * @param string $strategy 'prefer_newest'|'admin_override'|'pending'
     * @param array $row Incoming row data
     * @param Model $existing The existing model
     * @param array $fieldDiffs Computed field diffs
     * @param ImportJob $job The import job
     * @return array{action: 'apply'|'skip'|'conflict', resolvedRow: array|null}
     */
    public function resolve(
        string $strategy,
        array $row,
        Model $existing,
        array $fieldDiffs,
        ImportJob $job
    ): array {
        if ($strategy === 'prefer_newest') {
            return $this->resolvePreferNewest($row, $existing);
        }

        // 'admin_override' or 'pending' — create a conflict record
        return $this->createConflictRecord($row, $existing, $fieldDiffs, $job);
    }

    /**
     * Admin resolves a single conflict.
     *
     * @param ImportConflict $conflict
     * @param string $resolution 'prefer_newest' | 'admin_override'
     * @param array $resolvedRecord The record to apply (used for admin_override)
     * @param User $admin
     */
    public function adminResolve(
        ImportConflict $conflict,
        string $resolution,
        array $resolvedRecord,
        User $admin
    ): ImportConflict {
        if ($resolution === 'prefer_newest') {
            // Use the incoming record as the resolved record
            $resolvedRecord = $conflict->incoming_record ?? [];
        }

        $conflict->update([
            'resolution'      => $resolution,
            'resolved_record' => $resolvedRecord,
            'resolved_by'     => $admin->id,
            'resolved_at'     => now(),
        ]);

        return $conflict->refresh();
    }

    /**
     * Handle prefer_newest strategy.
     * If incoming last_updated_at > existing's, apply; otherwise skip.
     * If either has no timestamp, default to apply.
     */
    private function resolvePreferNewest(array $row, Model $existing): array
    {
        $incomingTs = isset($row['last_updated_at']) && $row['last_updated_at'] !== ''
            ? $this->parseTimestamp($row['last_updated_at'])
            : null;

        $existingTs = $existing->getAttribute('last_updated_at');
        if ($existingTs && is_string($existingTs)) {
            $existingTs = $this->parseTimestamp($existingTs);
        }

        // If either has no parseable timestamp, default to apply
        if ($incomingTs === null || $existingTs === null) {
            return ['action' => 'apply', 'resolvedRow' => $row];
        }

        if ($incomingTs > $existingTs) {
            return ['action' => 'apply', 'resolvedRow' => $row];
        }

        return ['action' => 'skip', 'resolvedRow' => null];
    }

    /**
     * Map import entity_type strings to the entity type keys used by
     * SensitiveDataClassification for redaction lookups.
     */
    private function redactionEntityType(ImportJob $job): ?string
    {
        return match ($job->entity_type) {
            'users'             => 'user',
            'user_profiles'     => 'user_profile',
            default             => null,
        };
    }

    /**
     * Create an ImportConflict record and return conflict action.
     *
     * Sensitive classified fields (e.g. employee_id) are redacted from
     * incoming_record and local_record before storage so that plaintext
     * PII never persists in the import_conflicts JSON columns.
     */
    private function createConflictRecord(
        array $row,
        Model $existing,
        array $fieldDiffs,
        ImportJob $job
    ): array {
        // Build a clean version of the existing record for storage
        $localRecord = $existing->toArray();

        // Redact classified sensitive fields from both records
        $entityType = $this->redactionEntityType($job);
        if ($entityType !== null) {
            $localRecord = $this->redactor->redact($entityType, $localRecord);
            $row         = $this->redactor->redact($entityType, $row);
        }

        // Strip internal runtime keys (e.g. _admin injected by processor)
        unset($row['_admin']);

        // Use the primary key or a unique identifier as the record_identifier
        $identifier = $existing->getKey() ? (string) $existing->getKey() : 'unknown';

        ImportConflict::create([
            'import_job_id'     => $job->id,
            'record_identifier' => $identifier,
            'local_record'      => $localRecord,
            'incoming_record'   => $row,
            'field_diffs'       => $fieldDiffs,
            'resolution'        => 'pending',
        ]);

        return ['action' => 'conflict', 'resolvedRow' => null];
    }

    /**
     * Parse a timestamp string to a DateTime instance.
     */
    private function parseTimestamp(mixed $value): ?\DateTimeInterface
    {
        if ($value instanceof \DateTimeInterface) {
            return $value;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return new \DateTime($value);
        } catch (\Exception) {
            return null;
        }
    }
}
