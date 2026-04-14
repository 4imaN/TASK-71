<?php

namespace App\Services\Auth;

use App\Models\PasswordHistory;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Validates password complexity rules and history constraints.
 *
 * Rules enforced:
 * - Minimum length (configurable, default 12)
 * - Uppercase, lowercase, digit, special character
 * - Not matching any of the last N passwords (configurable, default 5)
 * - Not same as current password
 */
class PasswordValidator
{
    public function __construct(
        private int $minLength = 12,
        private int $historyCount = 5,
    ) {}

    /**
     * Validate password complexity only (no history check).
     * Returns list of violation messages, empty array on pass.
     */
    public function validateComplexity(string $password): array
    {
        $errors = [];

        if (strlen($password) < $this->minLength) {
            $errors[] = "Password must be at least {$this->minLength} characters.";
        }
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter.';
        }
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter.';
        }
        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one digit.';
        }
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = 'Password must contain at least one special character.';
        }

        return $errors;
    }

    /**
     * Check password against the user's history.
     * Returns true if the password has been used before (violation).
     *
     * The check covers two sources:
     *
     *   1. users.password — the currently active hash.  Accounts provisioned
     *      via import (or any path that does not seed a password_history row)
     *      still have their credential here.  Without this check, a forced
     *      first-change could silently re-submit the same value because the
     *      password_history table would be empty and return no matches.
     *
     *   2. password_history rows — the last N hashes recorded after each
     *      successful change.
     */
    public function isInHistory(User $user, string $plainPassword): bool
    {
        // Always compare against the current active password first.
        // This closes the gap for accounts that have no password_history rows.
        if ($user->password && Hash::check($plainPassword, $user->password)) {
            return true;
        }

        $history = PasswordHistory::where('user_id', $user->id)
            ->latest('created_at')
            ->limit($this->historyCount)
            ->get();

        foreach ($history as $entry) {
            if (Hash::check($plainPassword, $entry->password_hash)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Full validation: complexity + history.
     * Returns list of violation messages, empty on pass.
     */
    public function validate(User $user, string $password): array
    {
        $errors = $this->validateComplexity($password);

        if (empty($errors) && $this->isInHistory($user, $password)) {
            $errors[] = "Password cannot match any of your last {$this->historyCount} passwords.";
        }

        return $errors;
    }

    /**
     * Record a new password in history after a successful change.
     * Prunes entries beyond the history limit.
     */
    public function recordInHistory(User $user, string $hashedPassword): void
    {
        PasswordHistory::create([
            'user_id'       => $user->id,
            'password_hash' => $hashedPassword,
            'created_at'    => now(),
        ]);

        // Prune old entries beyond limit
        $idsToKeep = PasswordHistory::where('user_id', $user->id)
            ->latest('created_at')
            ->limit($this->historyCount)
            ->pluck('id');

        PasswordHistory::where('user_id', $user->id)
            ->whereNotIn('id', $idsToKeep)
            ->delete();
    }
}
