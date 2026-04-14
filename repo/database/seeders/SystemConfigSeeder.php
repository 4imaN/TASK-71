<?php

namespace Database\Seeders;

use App\Models\SystemConfig;
use Illuminate\Database\Seeder;

class SystemConfigSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            // Reservation policy
            ['key' => 'pending_reservation_expiry_minutes', 'value' => '30',    'type' => 'integer',  'description' => 'Minutes before a pending reservation expires automatically'],
            ['key' => 'late_cancel_free_hours_before',     'value' => '24',    'type' => 'integer',  'description' => 'Hours before start time within which cancellation is free'],
            ['key' => 'late_cancel_consequence_type',      'value' => 'fee',   'type' => 'string',   'description' => 'Consequence for late cancellation: fee or points'],
            ['key' => 'late_cancel_fee_amount',            'value' => '25.00', 'type' => 'decimal',  'description' => 'Fee amount in USD for late cancellation'],
            ['key' => 'late_cancel_points_amount',         'value' => '50',    'type' => 'integer',  'description' => 'Points deducted for late cancellation'],
            ['key' => 'noshow_breach_window_days',         'value' => '60',    'type' => 'integer',  'description' => 'Rolling window in days for counting no-show breaches'],
            ['key' => 'noshow_breach_threshold',           'value' => '2',     'type' => 'integer',  'description' => 'Number of breaches in window that trigger a booking freeze'],
            ['key' => 'noshow_freeze_duration_days',       'value' => '7',     'type' => 'integer',  'description' => 'Days the booking freeze lasts after threshold is reached'],
            ['key' => 'checkin_opens_minutes_before',      'value' => '15',    'type' => 'integer',  'description' => 'Minutes before start time that check-in opens'],
            ['key' => 'checkin_closes_minutes_after',      'value' => '10',    'type' => 'integer',  'description' => 'Minutes after start time that check-in closes (late = partial attendance)'],
            // Authentication policy
            ['key' => 'password_min_length',               'value' => '12',    'type' => 'integer',  'description' => 'Minimum password length'],
            ['key' => 'password_history_count',            'value' => '5',     'type' => 'integer',  'description' => 'Number of previous passwords to check against'],
            ['key' => 'password_rotation_days',            'value' => '90',    'type' => 'integer',  'description' => 'Days before password rotation is required'],
            ['key' => 'idle_timeout_minutes',              'value' => '20',    'type' => 'integer',  'description' => 'Session idle timeout in minutes'],
            ['key' => 'brute_force_max_attempts',          'value' => '5',     'type' => 'integer',  'description' => 'Failed login attempts before account lockout'],
            ['key' => 'brute_force_lockout_minutes',       'value' => '15',    'type' => 'integer',  'description' => 'Account lockout duration in minutes after brute-force threshold'],
            ['key' => 'captcha_show_after_attempts',       'value' => '3',     'type' => 'integer',  'description' => 'Failed attempts count before CAPTCHA challenge is shown'],
            // Import
            ['key' => 'import_similarity_threshold',       'value' => '0.85',  'type' => 'decimal',  'description' => 'Title similarity threshold for duplicate detection (0–1)'],
            // Login anomaly
            ['key' => 'login_anomaly_time_window_start',   'value' => '6',     'type' => 'integer',  'description' => 'Hour (0-23) marking start of normal login window'],
            ['key' => 'login_anomaly_time_window_end',     'value' => '22',    'type' => 'integer',  'description' => 'Hour (0-23) marking end of normal login window'],
        ];

        foreach ($defaults as $row) {
            SystemConfig::firstOrCreate(
                ['key' => $row['key']],
                $row
            );
        }
    }
}
