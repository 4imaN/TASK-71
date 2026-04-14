<?php

namespace App\Services\Import\EntityStrategies;

use App\Models\Service;
use App\Models\User;
use App\Services\Admin\SystemConfigService;
use App\Services\Editor\ServiceEditorService;
use App\Services\Import\EntityStrategyInterface;
use Illuminate\Database\Eloquent\Model;

class ServiceStrategy implements EntityStrategyInterface
{
    public function __construct(
        private readonly ServiceEditorService $serviceEditor,
        private readonly SystemConfigService  $configService,
    ) {}

    /**
     * Normalize a title string for similarity comparison.
     */
    public static function normalizeTitle(string $text): string
    {
        $text = preg_replace('/[^a-z0-9 ]/i', '', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return strtolower(trim($text));
    }

    /**
     * Find existing Service by normalized title similarity.
     */
    public function findExisting(array $row): ?Model
    {
        if (empty($row['title'])) {
            return null;
        }

        $threshold    = $this->configService->importSimilarityThreshold();
        $incomingNorm = self::normalizeTitle($row['title']);

        $candidates = Service::withTrashed()->get(['id', 'title']);

        foreach ($candidates as $candidate) {
            $existingNorm = self::normalizeTitle($candidate->title);
            similar_text($incomingNorm, $existingNorm, $percent);
            if (($percent / 100) >= $threshold) {
                // Load full model
                return Service::withTrashed()->find($candidate->id);
            }
        }

        return null;
    }

    /**
     * Compute field-level diffs between incoming row and existing model.
     */
    public function computeFieldDiffs(array $row, Model $existing): array
    {
        $diffs = [];
        $fields = [
            'title', 'description', 'eligibility_notes',
            'category_id', 'service_type_id', 'is_free',
            'fee_amount', 'fee_currency', 'requires_manual_confirmation', 'status',
        ];

        foreach ($fields as $field) {
            if (!array_key_exists($field, $row)) {
                continue;
            }
            $incoming = $row[$field];
            $local    = $existing->getAttribute($field);

            $incomingNorm = (string) ($incoming ?? '');
            $localNorm    = (string) ($local ?? '');

            if ($incomingNorm !== $localNorm) {
                $diffs[] = [
                    'field'          => $field,
                    'local_value'    => $local,
                    'incoming_value' => $incoming,
                ];
            }
        }

        return $diffs;
    }

    /**
     * Create or update a Service via ServiceEditorService.
     * New services are created as draft. Updates go through the editor update method.
     *
     * Requires 'admin' key in row or falls back to a system user.
     */
    public function apply(array $row, ?Model $existing): Model
    {
        // Resolve the acting user — stored as _admin in row during import
        $admin = isset($row['_admin']) && $row['_admin'] instanceof User
            ? $row['_admin']
            : User::first();

        if ($existing) {
            $updateData = array_filter([
                'title'                        => $row['title'] ?? null,
                'description'                  => $row['description'] ?? null,
                'eligibility_notes'            => $row['eligibility_notes'] ?? null,
                'category_id'                  => $row['category_id'] ?? null,
                'service_type_id'              => $row['service_type_id'] ?? null,
                'is_free'                      => isset($row['is_free']) ? (bool) $row['is_free'] : null,
                'fee_amount'                   => $row['fee_amount'] ?? null,
                'fee_currency'                 => $row['fee_currency'] ?? null,
                'requires_manual_confirmation' => isset($row['requires_manual_confirmation'])
                    ? (bool) $row['requires_manual_confirmation']
                    : null,
            ], fn ($v) => $v !== null);

            return $this->serviceEditor->update($existing, $admin, $updateData);
        }

        // Create as draft — do not publish
        $createData = [
            'title'                        => $row['title'],
            'description'                  => $row['description'] ?? null,
            'eligibility_notes'            => $row['eligibility_notes'] ?? null,
            'category_id'                  => $row['category_id'] ?? null,
            'service_type_id'              => $row['service_type_id'] ?? null,
            'is_free'                      => isset($row['is_free']) ? (bool) $row['is_free'] : true,
            'fee_amount'                   => $row['fee_amount'] ?? 0,
            'fee_currency'                 => $row['fee_currency'] ?? 'USD',
            'requires_manual_confirmation' => isset($row['requires_manual_confirmation'])
                ? (bool) $row['requires_manual_confirmation']
                : false,
        ];

        return $this->serviceEditor->create($admin, $createData);
    }

    /**
     * Required fields.
     */
    public function requiredFields(): array
    {
        return ['title'];
    }
}
