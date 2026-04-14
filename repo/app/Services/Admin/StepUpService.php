<?php

namespace App\Services\Admin;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

class StepUpService
{
    public const SESSION_KEY  = 'admin_stepup_verified_at';
    public const TTL_MINUTES  = 15;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * Verify current password; on success store grant in session.
     */
    public function verify(User $user, string $password): bool
    {
        if (!Hash::check($password, $user->password)) {
            return false;
        }

        session([self::SESSION_KEY => now()->toIso8601String()]);

        $this->auditLogger->log(
            action: 'admin.stepup_verified',
            actorId: $user->id,
            entityType: 'user',
            entityId: $user->id,
        );

        return true;
    }

    /**
     * Check if a valid step-up grant exists in the current session.
     */
    public function isGranted(): bool
    {
        $ts = session(self::SESSION_KEY);
        if (!$ts) {
            return false;
        }

        return Carbon::parse($ts)->diffInMinutes(now()) < self::TTL_MINUTES;
    }

    /**
     * Revoke the current grant (e.g. on logout or explicit revocation).
     */
    public function revoke(): void
    {
        session()->forget(self::SESSION_KEY);
    }
}
