<?php

namespace App\Services\Import\EntityStrategies;

use App\Models\Department;
use App\Services\Import\EntityStrategyInterface;
use Illuminate\Database\Eloquent\Model;

class DepartmentStrategy implements EntityStrategyInterface
{
    /**
     * Find existing department by exact code match.
     */
    public function findExisting(array $row): ?Model
    {
        if (empty($row['code'])) {
            return null;
        }

        return Department::where('code', $row['code'])->first();
    }

    /**
     * Compute field-level diffs between incoming row and existing model.
     * Returns array of [{field, local_value, incoming_value}].
     */
    public function computeFieldDiffs(array $row, Model $existing): array
    {
        $diffs = [];
        $fields = ['name', 'parent_department_id', 'is_active', 'last_updated_at'];

        foreach ($fields as $field) {
            if (!array_key_exists($field, $row)) {
                continue;
            }
            $incoming = $row[$field];
            $local    = $existing->getAttribute($field);

            // Normalize for comparison
            $incomingNorm = is_bool($incoming) ? (int) $incoming : (string) ($incoming ?? '');
            $localNorm    = is_bool($local) ? (int) $local : (string) ($local ?? '');

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
     * Upsert department by code.
     */
    public function apply(array $row, ?Model $existing): Model
    {
        $data = array_filter([
            'code'                 => $row['code'] ?? null,
            'name'                 => $row['name'] ?? null,
            'parent_department_id' => $row['parent_department_id'] ?? null,
            'is_active'            => isset($row['is_active']) ? (bool) $row['is_active'] : null,
            'last_updated_at'      => $row['last_updated_at'] ?? null,
        ], fn ($v) => $v !== null);

        if ($existing) {
            $existing->update($data);
            return $existing->refresh();
        }

        return Department::create($data);
    }

    /**
     * Required fields to validate before processing.
     */
    public function requiredFields(): array
    {
        return ['code', 'name'];
    }
}
