<?php

namespace App\Services\Import\EntityStrategies;

use App\Models\User;
use App\Services\Import\EntityStrategyInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Import strategy for user account (entity_type = 'users').
 *
 * Identifies records by username — the unique offline identity key used
 * for all authentication in this system (no SSO, no external auth).
 *
 * On create, provisions a new account with must_change_password=true and
 * a cryptographically random temporary password.  Authentication remains
 * strictly offline username/password.  Because the temporary password is
 * unknown to the user, an administrator MUST set (or reset) the account's
 * credential via the admin user management surface before the account
 * becomes usable for login.
 *
 * Password data is NEVER read from import rows — credential management
 * is strictly separated from the HR/roster data-exchange workflow.
 */
class UserAccountStrategy implements EntityStrategyInterface
{
    /**
     * Find an existing User by exact username match.
     */
    public function findExisting(array $row): ?Model
    {
        if (empty($row['username'])) {
            return null;
        }

        return User::where('username', $row['username'])->first();
    }

    /**
     * Compute field-level diffs between incoming row and existing User.
     */
    public function computeFieldDiffs(array $row, Model $existing): array
    {
        $diffs  = [];
        $fields = ['display_name', 'status', 'audience_type'];

        foreach ($fields as $field) {
            if (!array_key_exists($field, $row)) {
                continue;
            }
            $incoming = $row[$field];
            $local    = $existing->getAttribute($field);

            if ((string) ($incoming ?? '') !== (string) ($local ?? '')) {
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
     * Upsert a User account by username.
     *
     * Update path: syncs display_name, status, and audience_type only.
     *              The account password is NEVER modified during import.
     * Create path: provisions a new account with must_change_password=true
     *              and a cryptographically random temporary password that
     *              is not communicated to the user.  An administrator must
     *              set the user's initial password via the admin user
     *              management surface before the account can be used.
     */
    public function apply(array $row, ?Model $existing): Model
    {
        if ($existing) {
            $updates = [];
            foreach (['display_name', 'status', 'audience_type'] as $field) {
                if (array_key_exists($field, $row) && $row[$field] !== null) {
                    $updates[$field] = $row[$field];
                }
            }
            if (!empty($updates)) {
                $existing->update($updates);
            }

            return $existing->refresh();
        }

        return User::create([
            'uuid'                 => (string) Str::uuid(),
            'username'             => $row['username'],
            'display_name'         => $row['display_name'],
            'password'             => bcrypt(Str::random(32)),
            'status'               => $row['status'] ?? 'active',
            'audience_type'        => $row['audience_type'] ?? null,
            'must_change_password' => true,
            'failed_attempts'      => 0,
        ]);
    }

    /**
     * Fields that must be non-empty for a row to be processable.
     */
    public function requiredFields(): array
    {
        return ['username', 'display_name'];
    }
}
