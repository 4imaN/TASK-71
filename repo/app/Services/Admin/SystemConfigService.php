<?php

namespace App\Services\Admin;

use App\Models\SystemConfig;
use Illuminate\Support\Facades\Cache;

/**
 * Typed access to system_config table values.
 * Caches resolved values to avoid per-request DB hits.
 */
class SystemConfigService
{
    private const CACHE_PREFIX = 'sysconfig:';
    private const CACHE_TTL    = 300; // 5 min

    public function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember(self::CACHE_PREFIX . $key, self::CACHE_TTL, function () use ($key, $default) {
            try {
                $config = SystemConfig::where('key', $key)->first();
                return $config ? $config->typedValue() : $default;
            } catch (\Exception) {
                // DB unavailable (e.g. during unit tests without a connection) — use default
                return $default;
            }
        });
    }

    public function getInt(string $key, int $default = 0): int
    {
        return (int) $this->get($key, $default);
    }

    public function getDecimal(string $key, float $default = 0.0): float
    {
        return (float) $this->get($key, $default);
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $val = $this->get($key, $default);
        return filter_var($val, FILTER_VALIDATE_BOOLEAN);
    }

    public function set(string $key, mixed $value, ?int $updatedBy = null): void
    {
        SystemConfig::updateOrCreate(
            ['key' => $key],
            ['value' => (string) $value, 'updated_by' => $updatedBy, 'updated_at' => now()]
        );

        Cache::forget(self::CACHE_PREFIX . $key);
    }

    // ── Typed shortcut accessors for policy constants ─────────────────────────

    public function pendingExpiryMinutes(): int        { return $this->getInt('pending_reservation_expiry_minutes', 30); }
    public function lateCancelFreeHours(): int         { return $this->getInt('late_cancel_free_hours_before', 24); }
    public function lateCancelConsequenceType(): string { return $this->get('late_cancel_consequence_type', 'fee'); }
    public function lateCancelFeeAmount(): float       { return $this->getDecimal('late_cancel_fee_amount', 25.00); }
    public function lateCancelPointsAmount(): int      { return $this->getInt('late_cancel_points_amount', 50); }
    public function noshowBreachWindowDays(): int      { return $this->getInt('noshow_breach_window_days', 60); }
    public function noshowBreachThreshold(): int       { return $this->getInt('noshow_breach_threshold', 2); }
    public function noshowFreezeDays(): int            { return $this->getInt('noshow_freeze_duration_days', 7); }
    public function checkinOpensMinsBeforeStart(): int { return $this->getInt('checkin_opens_minutes_before', 15); }
    public function checkinClosedMinsAfterStart(): int { return $this->getInt('checkin_closes_minutes_after', 10); }
    public function passwordMinLength(): int           { return $this->getInt('password_min_length', 12); }
    public function passwordHistoryCount(): int        { return $this->getInt('password_history_count', 5); }
    public function passwordRotationDays(): int        { return $this->getInt('password_rotation_days', 90); }
    public function idleTimeoutMinutes(): int          { return $this->getInt('idle_timeout_minutes', 20); }
    public function bruteForceMaxAttempts(): int       { return $this->getInt('brute_force_max_attempts', 5); }
    public function bruteForceLockoutMinutes(): int    { return $this->getInt('brute_force_lockout_minutes', 15); }
    public function captchaShowAfterAttempts(): int    { return $this->getInt('captcha_show_after_attempts', 5); }
    public function importSimilarityThreshold(): float { return $this->getDecimal('import_similarity_threshold', 0.85); }
    public function loginAnomalyTimeWindowStart(): int { return $this->getInt('login_anomaly_time_window_start', 6); }
    public function loginAnomalyTimeWindowEnd(): int   { return $this->getInt('login_anomaly_time_window_end', 22); }
}
