<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Admin\SystemConfigService;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Hash;

/**
 * Orchestrates all password-change flows:
 *   - voluntary change (authenticated user)
 *   - forced change (must_change_password = true, set by admin or import)
 *   - rotation-expired change (password_changed_at + rotation_days in past)
 *
 * Reads password_min_length, password_history_count, and
 * password_rotation_days from SystemConfigService so that runtime
 * policy changes take effect without a deployment.
 */
class PasswordChangeService
{
    public function __construct(
        private readonly PasswordValidator  $validator,
        private readonly SystemConfigService $config,
        private readonly AuditLogger         $auditLogger,
    ) {}

    /**
     * Attempt to change a user's password.
     *
     * Steps:
     *   1. Verify current password.
     *   2. Run complexity + history validation with config-driven thresholds.
     *   3. Hash and persist the new password; clear must_change_password;
     *      stamp password_changed_at.
     *   4. Record new hash in password_history, prune old entries.
     *   5. Audit-log the event.
     *
     * @return array{ok: bool, errors: string[]}
     */
    public function change(User $user, string $currentPassword, string $newPassword): array
    {
        // 1. Verify current password
        if (!Hash::check($currentPassword, $user->password)) {
            return ['ok' => false, 'errors' => ['Current password is incorrect.']];
        }

        // 2. Complexity + history (re-instantiate with live config values)
        $errors = $this->configuredValidator()->validate($user, $newPassword);

        if (!empty($errors)) {
            return ['ok' => false, 'errors' => $errors];
        }

        // 3. Persist
        $newHash = Hash::make($newPassword);

        $user->update([
            'password'             => $newHash,
            'password_changed_at'  => now(),
            'must_change_password' => false,
        ]);

        // 4. History
        $this->configuredValidator()->recordInHistory($user, $newHash);

        // 5. Audit
        $this->auditLogger->log(
            action:     'auth.password_changed',
            actorId:    $user->id,
            entityType: 'user',
            entityId:   $user->id,
        );

        return ['ok' => true, 'errors' => []];
    }

    /**
     * Whether the user's password has exceeded the rotation window.
     *
     * Returns false when rotation_days = 0 (rotation disabled).
     * Returns true when password_changed_at is null (password was never
     * explicitly set — e.g. imported accounts with a random temp password).
     */
    public function isRotationExpired(User $user): bool
    {
        $days = $this->config->passwordRotationDays();

        if ($days === 0) {
            return false;
        }

        if (!$user->password_changed_at) {
            return true;
        }

        return $user->password_changed_at->copy()->addDays($days)->isPast();
    }

    /**
     * Whether the user is required to change their password right now.
     * Covers both the explicit flag and rotation expiry.
     */
    public function mustChange(User $user): bool
    {
        return (bool) $user->must_change_password || $this->isRotationExpired($user);
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    /**
     * Build a PasswordValidator instance that reflects the current
     * system-config values for min_length and history_count.
     */
    private function configuredValidator(): PasswordValidator
    {
        return new PasswordValidator(
            minLength:    $this->config->passwordMinLength(),
            historyCount: $this->config->passwordHistoryCount(),
        );
    }
}
