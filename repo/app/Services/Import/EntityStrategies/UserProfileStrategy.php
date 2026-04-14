<?php

namespace App\Services\Import\EntityStrategies;

use App\Models\User;
use App\Models\UserProfile;
use App\Services\Import\EntityStrategyInterface;
use Illuminate\Database\Eloquent\Model;


class UserProfileStrategy implements EntityStrategyInterface
{
    /**
     * Find existing UserProfile by exact employee_id match.
     *
     * employee_id is encrypted at rest (non-deterministic ciphertext), so a
     * plain WHERE clause cannot match.  Instead we query the deterministic
     * HMAC-SHA256 blind-index column (employee_id_hash).
     */
    public function findExisting(array $row): ?Model
    {
        if (empty($row['employee_id'])) {
            return null;
        }

        return UserProfile::findByEmployeeId($row['employee_id']);
    }

    /**
     * Compute field-level diffs between incoming row and existing model.
     * user_id is excluded — it is an internal FK resolved at apply() time,
     * never diffed directly from import data.
     */
    public function computeFieldDiffs(array $row, Model $existing): array
    {
        $diffs = [];
        $fields = [
            'department_id', 'cost_center', 'job_title',
            'employment_classification_id', 'employment_status', 'last_updated_at',
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
     * Upsert UserProfile by employee_id.
     *
     * HR/roster export files carry a username column but not the app's
     * internal user_id FK.  Resolution order:
     *   1. Explicit user_id in the row (direct export from internal tooling).
     *   2. Existing profile's user_id on the update path (avoids re-lookup).
     *   3. User lookup by the 'username' column in the row.  Username is the
     *      offline identity key for this system; the User account must already
     *      exist in the app before a profile row can be linked to it.
     *
     * Throws RuntimeException only when none of the three paths yields a
     * user_id, surfacing as a failed row in the import job error log.
     */
    public function apply(array $row, ?Model $existing): Model
    {
        $userId = $row['user_id'] ?? null;

        // Path 2 — carry forward from existing profile on update
        if (empty($userId) && $existing) {
            $userId = $existing->user_id;
        }

        // Path 3 — HR/roster source supplies username; look up the existing offline User account
        if (empty($userId) && !empty($row['username'])) {
            $user = User::where('username', $row['username'])->first();
            if ($user) {
                $userId = $user->id;
            }
        }

        if (empty($userId)) {
            throw new \RuntimeException(
                "Cannot resolve user_id for employee_id={$row['employee_id']}. " .
                "Provide 'username' (the account's offline identity key) or 'user_id' explicitly in the import file."
            );
        }

        $data = [
            'employee_id'                  => $row['employee_id'],
            'user_id'                      => $userId,
            'department_id'                => $row['department_id'] ?? ($existing?->department_id),
            'cost_center'                  => $row['cost_center'] ?? ($existing?->cost_center),
            'job_title'                    => $row['job_title'] ?? ($existing?->job_title),
            'employment_classification_id' => $row['employment_classification_id'] ?? ($existing?->employment_classification_id),
            'employment_status'            => $row['employment_status'] ?? ($existing?->employment_status),
            'last_updated_at'              => $row['last_updated_at'] ?? ($existing?->last_updated_at),
        ];

        // Remove nulls for existing so we don't overwrite with null unintentionally
        $data = array_filter($data, fn ($v) => $v !== null);

        if ($existing) {
            $existing->update($data);
            return $existing->refresh();
        }

        return UserProfile::create($data);
    }

    /**
     * Required fields.
     */
    public function requiredFields(): array
    {
        return ['employee_id'];
    }
}
