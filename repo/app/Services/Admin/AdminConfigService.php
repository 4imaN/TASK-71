<?php

namespace App\Services\Admin;

use App\Models\SystemConfig;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AdminConfigService
{
    /** Group definitions — ordered as displayed in the UI. */
    public const GROUPS = [
        'reservation' => [
            'pending_reservation_expiry_minutes',
            'late_cancel_free_hours_before',
            'late_cancel_consequence_type',
            'late_cancel_fee_amount',
            'late_cancel_points_amount',
            'noshow_breach_window_days',
            'noshow_breach_threshold',
            'noshow_freeze_duration_days',
            'checkin_opens_minutes_before',
            'checkin_closes_minutes_after',
        ],
        'auth' => [
            'password_min_length',
            'password_history_count',
            'password_rotation_days',
            'idle_timeout_minutes',
            'brute_force_max_attempts',
            'brute_force_lockout_minutes',
            'captcha_show_after_attempts',
        ],
        'import' => [
            'import_similarity_threshold',
        ],
        'login_anomaly' => [
            'login_anomaly_time_window_start',
            'login_anomaly_time_window_end',
        ],
    ];

    /** Per-key validation rules (Laravel format). */
    public const VALIDATION = [
        'pending_reservation_expiry_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
        'late_cancel_free_hours_before'      => ['required', 'integer', 'min:0', 'max:168'],
        'late_cancel_consequence_type'       => ['required', 'string', 'in:fee,points,none'],
        'late_cancel_fee_amount'             => ['required', 'numeric', 'min:0'],
        'late_cancel_points_amount'          => ['required', 'integer', 'min:0'],
        'noshow_breach_window_days'          => ['required', 'integer', 'min:1', 'max:365'],
        'noshow_breach_threshold'            => ['required', 'integer', 'min:1', 'max:10'],
        'noshow_freeze_duration_days'        => ['required', 'integer', 'min:1', 'max:365'],
        'checkin_opens_minutes_before'       => ['required', 'integer', 'min:0', 'max:120'],
        'checkin_closes_minutes_after'       => ['required', 'integer', 'min:0', 'max:120'],
        'password_min_length'                => ['required', 'integer', 'min:8', 'max:128'],
        'password_history_count'             => ['required', 'integer', 'min:0', 'max:24'],
        'password_rotation_days'             => ['required', 'integer', 'min:0', 'max:365'],
        'idle_timeout_minutes'               => ['required', 'integer', 'min:5', 'max:480'],
        'brute_force_max_attempts'           => ['required', 'integer', 'min:1', 'max:20'],
        'brute_force_lockout_minutes'        => ['required', 'integer', 'min:1', 'max:1440'],
        'captcha_show_after_attempts'        => ['required', 'integer', 'min:1', 'max:10'],
        'import_similarity_threshold'        => ['required', 'numeric', 'min:0', 'max:1'],
        'login_anomaly_time_window_start'    => ['required', 'integer', 'min:0', 'max:23'],
        'login_anomaly_time_window_end'      => ['required', 'integer', 'min:0', 'max:23'],
    ];

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * Return all configs keyed by group, enriched with metadata from DB.
     */
    public function allGrouped(): array
    {
        $all = SystemConfig::all()->keyBy('key');
        $result = [];

        foreach (self::GROUPS as $group => $keys) {
            foreach ($keys as $key) {
                $row = $all->get($key);
                $result[$group][] = [
                    'key'          => $key,
                    'value'        => $row?->value,
                    'type'         => $row?->type ?? 'string',
                    'description'  => $row?->description,
                    'is_sensitive' => $row?->is_sensitive ?? false,
                ];
            }
        }

        return $result;
    }

    /**
     * All known keys (flat).
     */
    public function knownKeys(): array
    {
        return array_merge(...array_values(self::GROUPS));
    }

    /**
     * Update a single config key.
     *
     * @throws \InvalidArgumentException for unknown keys
     */
    public function update(string $key, mixed $value, User $admin): SystemConfig
    {
        if (!in_array($key, $this->knownKeys())) {
            throw new \InvalidArgumentException("Unknown configuration key: {$key}");
        }

        $before = SystemConfig::where('key', $key)->first();

        $config = SystemConfig::updateOrCreate(
            ['key' => $key],
            ['value' => (string) $value, 'updated_by' => $admin->id, 'updated_at' => now()]
        );

        Cache::forget('sysconfig:' . $key);

        $this->auditLogger->log(
            action: 'admin.config_updated',
            actorId: $admin->id,
            entityType: 'system_config',
            entityId: $config->id,
            beforeState: ['key' => $key, 'value' => $before?->value],
            afterState:  ['key' => $key, 'value' => (string) $value],
        );

        return $config->refresh();
    }

    /**
     * Bulk update a key→value map. All are validated before any are written.
     *
     * @throws \InvalidArgumentException for unknown keys
     * @throws ValidationException for invalid values
     */
    public function updateBulk(array $changes, User $admin): void
    {
        $rules = [];
        foreach ($changes as $key => $value) {
            if (!in_array($key, $this->knownKeys())) {
                throw new \InvalidArgumentException("Unknown configuration key: {$key}");
            }
            $rules[$key] = self::VALIDATION[$key] ?? ['required'];
        }

        Validator::make($changes, $rules)->validate();

        foreach ($changes as $key => $value) {
            $this->update($key, $value, $admin);
        }
    }
}
