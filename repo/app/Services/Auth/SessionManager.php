<?php

namespace App\Services\Auth;

use App\Models\AppSession;
use App\Models\User;
use App\Services\Admin\SystemConfigService;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Manages custom session lifecycle: creation, validation, single-logout,
 * idle timeout enforcement, device fingerprinting, and login anomaly detection.
 */
class SessionManager
{
    public function __construct(
        private readonly AuditLogger          $auditLogger,
        private readonly SystemConfigService  $config,
        private int $idleTimeoutMinutes = 20,
    ) {}

    /**
     * Record a new session for the given user after successful login.
     *
     * Two-step approach to avoid PostgreSQL-specific upsert pitfalls:
     *
     *   Step 1 — insertOrIgnore() = INSERT … ON CONFLICT DO NOTHING
     *   After session()->regenerate() the new session ID may not yet have a row.
     *   insertOrIgnore() provides required NOT NULL values (payload, last_activity)
     *   on the INSERT path and silently skips the write when the row already exists,
     *   without aborting the surrounding transaction (unlike a plain INSERT conflict).
     *
     *   Step 2 — update() refreshes security-tracking columns on the now-guaranteed
     *   row without touching `payload` or `last_activity`, which are managed
     *   exclusively by Laravel's session store.
     */
    public function recordSession(User $user, Request $request, string $sessionId): void
    {
        $fingerprint = $this->fingerprint($request);
        $now         = now();

        // Step 1: ensure the row exists with all NOT NULL columns satisfied.
        // ON CONFLICT DO NOTHING — safe to call even when the row already exists.
        DB::table('sessions')->insertOrIgnore([
            'id'                 => $sessionId,
            'user_id'            => $user->id,
            'ip_address'         => $request->ip(),
            'user_agent'         => substr($request->userAgent() ?? '', 0, 255),
            // Placeholder payload — overwritten by Laravel's session store at response-end.
            // Must be non-null to satisfy the DB constraint on the INSERT path.
            'payload'            => base64_encode(serialize([])),
            'last_activity'      => time(),
            'device_fingerprint' => $fingerprint,
            'last_active_at'     => $now,
            'revoked_at'         => null,
        ]);

        // Step 2: refresh security columns on the now-guaranteed row.
        // Deliberately excludes `payload` so Laravel's session data is never cleared.
        DB::table('sessions')->where('id', $sessionId)->update([
            'user_id'            => $user->id,
            'ip_address'         => $request->ip(),
            'user_agent'         => substr($request->userAgent() ?? '', 0, 255),
            'last_activity'      => time(),
            'device_fingerprint' => $fingerprint,
            'last_active_at'     => $now,
            'revoked_at'         => null,
        ]);
    }

    /**
     * Detect login anomalies and write immutable audit entries for each one found.
     *
     * Call this BEFORE recordSession() so the current session is not yet
     * fingerprinted, giving a clean signal for "first time this fingerprint".
     *
     * Detects:
     *   - new_device: fingerprint not previously recorded for this user
     *   - unusual_login_time: login hour outside the configured normal window
     */
    public function detectAndAuditLoginAnomalies(User $user, Request $request): void
    {
        $fingerprint = $this->fingerprint($request);

        // 1. New device fingerprint — check whether any previous session for this
        //    user already carries this fingerprint in the sessions table.
        $seenBefore = AppSession::where('user_id', $user->id)
            ->where('device_fingerprint', $fingerprint)
            ->exists();

        if (!$seenBefore) {
            $this->auditLogger->log(
                action:     'auth.login.anomaly',
                actorId:    $user->id,
                entityType: 'user',
                entityId:   $user->id,
                metadata: [
                    'anomaly_type' => 'new_device',
                    // Store a partial fingerprint in the audit log — enough to
                    // correlate but not a complete reproduction of the hash.
                    'fingerprint_prefix' => substr($fingerprint, 0, 16),
                    'ip_address'         => $request->ip(),
                    'user_agent'         => substr($request->userAgent() ?? '', 0, 200),
                ],
            );
        }

        // 2. Unusual login time — hour is outside [window_start, window_end).
        $windowStart = $this->config->loginAnomalyTimeWindowStart();
        $windowEnd   = $this->config->loginAnomalyTimeWindowEnd();
        $currentHour = (int) now()->format('G'); // 0–23

        // Support wrap-around (e.g. start=22, end=6 means 22:00–05:59 is normal).
        $outsideWindow = ($windowStart <= $windowEnd)
            ? ($currentHour < $windowStart || $currentHour >= $windowEnd)
            : ($currentHour >= $windowEnd && $currentHour < $windowStart);

        if ($outsideWindow) {
            $this->auditLogger->log(
                action:     'auth.login.anomaly',
                actorId:    $user->id,
                entityType: 'user',
                entityId:   $user->id,
                metadata: [
                    'anomaly_type' => 'unusual_login_time',
                    'current_hour' => $currentHour,
                    'window_start' => $windowStart,
                    'window_end'   => $windowEnd,
                ],
            );
        }
    }

    /**
     * Revoke all active sessions for a user (single-logout).
     */
    public function revokeAllSessions(User $user, string $reason = 'logout'): int
    {
        $count = AppSession::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        $this->auditLogger->log(
            action: 'auth.session_revoked',
            actorId: $user->id,
            entityType: 'user',
            entityId: $user->id,
            metadata: ['reason' => $reason, 'sessions_revoked' => $count],
        );

        return $count;
    }

    /**
     * Check whether the current session is still valid (not revoked, not idle).
     * Returns false if the session should be terminated.
     */
    public function isSessionValid(string $sessionId): bool
    {
        $session = AppSession::find($sessionId);

        if (!$session) {
            return false;
        }

        if ($session->isRevoked()) {
            return false;
        }

        if ($session->last_active_at
            && $session->last_active_at->diffInMinutes(now()) > $this->idleTimeoutMinutes
        ) {
            return false;
        }

        return true;
    }

    /**
     * Touch the session's last_active_at timestamp.
     */
    public function touchSession(string $sessionId): void
    {
        AppSession::where('id', $sessionId)
            ->whereNull('revoked_at')
            ->update(['last_active_at' => now()]);
    }

    public function fingerprint(Request $request): string
    {
        $subnet = implode('.', array_slice(explode('.', $request->ip() ?? ''), 0, 3));
        return hash('sha256', implode('|', [
            $request->userAgent() ?? '',
            $request->header('Accept-Language', ''),
            $subnet,
        ]));
    }
}
