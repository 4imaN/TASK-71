<?php

namespace App\Services\Admin;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\PasswordValidator;
use App\Services\Auth\SessionManager;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Administrator service for account governance and access-control operations.
 *
 * All write operations are audit-logged.  Step-up verification is enforced
 * at the controller/component call-site — not inside this service — so that
 * the service remains testable without a live session.
 *
 * Safe-guard: revokeRole() blocks removal of the last active administrator.
 */
class UserGovernanceService
{
    public function __construct(
        private readonly AuditLogger    $auditLogger,
        private readonly SessionManager $sessionManager,
    ) {}

    // ── Listing ───────────────────────────────────────────────────────────────

    /**
     * Paginated user list with optional filters.
     *
     * Supported filters:
     *   search  – partial match on username or display_name
     *   status  – exact match on status enum
     *   role    – exact role name (whereHas)
     */
    public function list(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $query = User::with(['roles', 'profile'])
            ->withTrashed()
            ->orderBy('username');

        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($term) {
                $q->where('username', 'LIKE', $term)
                  ->orWhere('display_name', 'LIKE', $term);
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['role'])) {
            $query->role($filters['role']);
        }

        return $query->paginate($perPage);
    }

    /**
     * Single user with relationships for the detail panel.
     */
    public function show(int $userId): User
    {
        return User::with(['roles', 'profile', 'appSessions' => function ($q) {
            $q->whereNull('revoked_at')->latest('last_active_at')->limit(5);
        }])->withTrashed()->findOrFail($userId);
    }

    // ── Account status operations ─────────────────────────────────────────────

    /**
     * Lock an account for a specified duration.
     * Duration defaults to 24 hours if not provided.
     */
    public function lockAccount(User $target, User $admin, ?\DateTimeInterface $until = null): User
    {
        $until ??= now()->addHours(24);
        $before = $target->only(['status', 'locked_until']);

        $target->update([
            'status'       => 'locked',
            'locked_until' => $until,
        ]);

        $this->auditLogger->log(
            action:      'user.account_locked',
            actorId:     $admin->id,
            entityType:  'user',
            entityId:    $target->id,
            beforeState: $before,
            afterState:  $target->fresh()->only(['status', 'locked_until']),
        );

        return $target->refresh();
    }

    /**
     * Unlock a locked account (restores status to active).
     */
    public function unlockAccount(User $target, User $admin): User
    {
        $before = $target->only(['status', 'locked_until']);

        $target->update([
            'status'       => 'active',
            'locked_until' => null,
        ]);

        $this->auditLogger->log(
            action:      'user.account_unlocked',
            actorId:     $admin->id,
            entityType:  'user',
            entityId:    $target->id,
            beforeState: $before,
            afterState:  $target->fresh()->only(['status', 'locked_until']),
        );

        return $target->refresh();
    }

    /**
     * Suspend an account indefinitely (requires admin reactivation).
     */
    public function suspendAccount(User $target, User $admin, ?string $reason = null): User
    {
        $before = $target->only(['status']);

        $target->update(['status' => 'suspended']);

        $this->auditLogger->log(
            action:      'user.account_suspended',
            actorId:     $admin->id,
            entityType:  'user',
            entityId:    $target->id,
            beforeState: $before,
            afterState:  ['status' => 'suspended'],
            metadata:    $reason ? ['reason' => $reason] : [],
        );

        return $target->refresh();
    }

    /**
     * Reactivate a suspended or locked account.
     */
    public function reactivateAccount(User $target, User $admin): User
    {
        $before = $target->only(['status', 'locked_until']);

        $target->update([
            'status'       => 'active',
            'locked_until' => null,
        ]);

        $this->auditLogger->log(
            action:      'user.account_reactivated',
            actorId:     $admin->id,
            entityType:  'user',
            entityId:    $target->id,
            beforeState: $before,
            afterState:  ['status' => 'active'],
        );

        return $target->refresh();
    }

    // ── Credential / session operations ───────────────────────────────────────

    /**
     * Force the target user to change password on next login.
     */
    public function forcePasswordReset(User $target, User $admin): User
    {
        $target->update(['must_change_password' => true]);

        $this->auditLogger->log(
            action:     'user.force_password_reset',
            actorId:    $admin->id,
            entityType: 'user',
            entityId:   $target->id,
            afterState: ['must_change_password' => true],
        );

        return $target->refresh();
    }

    /**
     * Administrator sets an initial password for a user account.
     *
     * Primary use case: imported accounts are provisioned with a random
     * temporary password that nobody knows.  This method lets an admin
     * set a known credential so the user can log in for the first time.
     *
     * The new password must pass complexity validation.
     * must_change_password is set to true so the user is forced to choose
     * their own password on first login.
     *
     * @return array{ok: bool, errors: string[]}
     */
    public function setInitialPassword(User $target, string $newPassword, User $admin): array
    {
        $configService = app(SystemConfigService::class);

        $validator = new PasswordValidator(
            minLength: $configService->passwordMinLength(),
            historyCount: $configService->passwordHistoryCount(),
        );

        $errors = $validator->validateComplexity($newPassword);
        if (!empty($errors)) {
            return ['ok' => false, 'errors' => $errors];
        }

        $newHash = Hash::make($newPassword);

        $target->update([
            'password'             => $newHash,
            'password_changed_at'  => now(),
            'must_change_password' => true,
        ]);

        // Record in password history so the user cannot reuse this password
        $validator->recordInHistory($target, $newHash);

        $this->auditLogger->log(
            action:     'user.initial_password_set',
            actorId:    $admin->id,
            entityType: 'user',
            entityId:   $target->id,
            afterState: ['must_change_password' => true],
        );

        return ['ok' => true, 'errors' => []];
    }

    /**
     * Revoke all active sessions for the target user.
     * Uses SessionManager so the action is consistently audit-logged.
     */
    public function revokeSessions(User $target, User $admin): int
    {
        $count = $this->sessionManager->revokeAllSessions(
            $target,
            reason: "admin_revoke:actor={$admin->id}"
        );

        // Additional governance-specific audit entry
        $this->auditLogger->log(
            action:     'user.sessions_revoked_by_admin',
            actorId:    $admin->id,
            entityType: 'user',
            entityId:   $target->id,
            afterState: ['sessions_revoked' => $count],
        );

        return $count;
    }

    // ── Account deletion ──────────────────────────────────────────────────────

    /**
     * Soft-delete an account.
     *
     * Safe-guards (consistent with the role-revocation guard):
     *   - Blocks deletion if the target is the only remaining active administrator.
     *   - Revokes all active sessions before deleting.
     *
     * @throws \RuntimeException if this would remove the last active administrator
     */
    public function deleteAccount(User $target, User $admin, ?string $reason = null): void
    {
        if ($target->hasRole('administrator')) {
            $activeAdminCount = User::role('administrator')
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->count();

            if ($activeAdminCount <= 1) {
                throw new \RuntimeException(
                    'Cannot delete the last active administrator. ' .
                    'Assign the administrator role to another account first.'
                );
            }
        }

        // Revoke live sessions so the user is kicked out immediately
        $this->sessionManager->revokeAllSessions(
            $target,
            reason: "admin_delete:actor={$admin->id}"
        );

        $before = $target->only(['status', 'username', 'display_name']);

        $target->delete(); // soft-delete via SoftDeletes trait

        $this->auditLogger->log(
            action:      'user.account_deleted',
            actorId:     $admin->id,
            entityType:  'user',
            entityId:    $target->id,
            beforeState: $before,
            afterState:  ['deleted_at' => now()->toIso8601String()],
            metadata:    $reason ? ['reason' => $reason] : [],
        );
    }

    // ── Role management ───────────────────────────────────────────────────────

    /**
     * Assign a role to the target user.
     *
     * @throws \InvalidArgumentException if the role does not exist
     */
    public function assignRole(User $target, string $roleName, User $admin): User
    {
        $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();

        if (!$role) {
            throw new \InvalidArgumentException("Role '{$roleName}' does not exist.");
        }

        $target->assignRole($role);

        $this->auditLogger->log(
            action:     'user.role_assigned',
            actorId:    $admin->id,
            entityType: 'user',
            entityId:   $target->id,
            afterState: ['role' => $roleName],
        );

        return $target->refresh();
    }

    /**
     * Revoke a role from the target user.
     *
     * Blocks removal of the last active administrator role to prevent
     * complete loss of system access.
     *
     * @throws \RuntimeException  if this would remove the last active admin
     * @throws \InvalidArgumentException if the role does not exist
     */
    public function revokeRole(User $target, string $roleName, User $admin): User
    {
        $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();

        if (!$role) {
            throw new \InvalidArgumentException("Role '{$roleName}' does not exist.");
        }

        if ($roleName === 'administrator') {
            $activeAdminCount = User::role('administrator')
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->count();

            if ($activeAdminCount <= 1 && $target->hasRole('administrator')) {
                throw new \RuntimeException(
                    'Cannot revoke the administrator role from the last active administrator. ' .
                    'Assign it to another account first.'
                );
            }
        }

        $target->removeRole($role);

        $this->auditLogger->log(
            action:     'user.role_revoked',
            actorId:    $admin->id,
            entityType: 'user',
            entityId:   $target->id,
            afterState: ['role' => $roleName],
        );

        return $target->refresh();
    }
}
