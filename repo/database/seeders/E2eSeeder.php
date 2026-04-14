<?php

namespace Database\Seeders;

use App\Models\DataDictionaryType;
use App\Models\DataDictionaryValue;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\Auth\PasswordValidator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * E2eSeeder — creates deterministic, fixed-credential test fixtures for
 * Playwright browser E2E tests.
 *
 * Credentials are intentionally predictable and hard-coded so Playwright
 * tests can log in without any runtime secret lookup.
 *
 * NEVER use this seeder in production.  It is called exclusively from
 * run_e2e.sh inside the ephemeral docker-compose.e2e.yml environment.
 *
 * Depends on: DataDictionarySeeder (for service_type_id FK lookup)
 */
class E2eSeeder extends Seeder
{
    // Known E2E credentials — must match e2e/helpers.ts
    public const ADMIN_USERNAME    = 'e2e_admin';
    public const ADMIN_PASSWORD    = 'AdminE2e1!';
    public const LEARNER_USERNAME  = 'e2e_learner';
    public const LEARNER_PASSWORD  = 'LearnerE2e1!';

    public function run(): void
    {
        // ── Roles must exist ─────────────────────────────────────────────────
        Role::firstOrCreate(['name' => 'administrator',  'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'content_editor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'learner',        'guard_name' => 'web']);

        // ── E2E admin user ────────────────────────────────────────────────────
        $admin = User::firstOrCreate(
            ['username' => self::ADMIN_USERNAME],
            [
                'uuid'                => (string) Str::uuid(),
                'display_name'        => 'E2E Admin',
                'password'            => Hash::make(self::ADMIN_PASSWORD),
                'password_changed_at' => now(),
                'audience_type'       => 'staff',
                'status'              => 'active',
                'must_change_password' => false,
            ]
        );
        $admin->assignRole('administrator');
        app(PasswordValidator::class)->recordInHistory($admin, Hash::make(self::ADMIN_PASSWORD));

        // ── E2E learner user ──────────────────────────────────────────────────
        $learner = User::firstOrCreate(
            ['username' => self::LEARNER_USERNAME],
            [
                'uuid'                => (string) Str::uuid(),
                'display_name'        => 'E2E Learner',
                'password'            => Hash::make(self::LEARNER_PASSWORD),
                'password_changed_at' => now(),
                'audience_type'       => 'graduate',
                'status'              => 'active',
                'must_change_password' => false,
            ]
        );
        $learner->assignRole('learner');
        app(PasswordValidator::class)->recordInHistory($learner, Hash::make(self::LEARNER_PASSWORD));

        // ── Service catalog fixtures ──────────────────────────────────────────
        $category = ServiceCategory::firstOrCreate(
            ['slug' => 'e2e-research-support'],
            [
                'name'        => 'Research Support',
                'description' => 'E2E test category',
                'sort_order'  => 99,
                'is_active'   => true,
            ]
        );

        // service_type_id requires DataDictionarySeeder to have run first
        $serviceType = DataDictionaryValue::whereHas('type', function ($q) {
            $q->where('code', 'service_type');
        })->where('key', 'consultation')->first();

        if (!$serviceType) {
            // Fallback: create the type/value inline if DataDictionarySeeder didn't run
            $dictType = DataDictionaryType::firstOrCreate(
                ['code' => 'service_type'],
                ['label' => 'Service Type']
            );
            $serviceType = DataDictionaryValue::firstOrCreate(
                ['type_id' => $dictType->id, 'key' => 'consultation'],
                ['label' => 'Consultation', 'sort_order' => 1, 'is_active' => true]
            );
        }

        $service = Service::firstOrCreate(
            ['slug' => 'e2e-data-consultation'],
            [
                'uuid'                       => (string) Str::uuid(),
                'title'                      => 'Data Consultation (E2E)',
                'description'                => 'End-to-end test service for Playwright verification.',
                'category_id'                => $category->id,
                'service_type_id'            => $serviceType->id,
                'is_free'                    => true,
                'requires_manual_confirmation' => false,
                'status'                     => 'active',
                'created_by'                 => $admin->id,
            ]
        );

        // Create two upcoming time slots so the booking flow has options
        $slotBase = now()->addDays(7);
        for ($i = 0; $i < 2; $i++) {
            TimeSlot::firstOrCreate(
                [
                    'service_id' => $service->id,
                    'starts_at'  => $slotBase->copy()->addHours($i * 2)->startOfHour(),
                ],
                [
                    'uuid'        => (string) Str::uuid(),
                    'ends_at'     => $slotBase->copy()->addHours($i * 2 + 1)->startOfHour(),
                    'capacity'    => 5,
                    'booked_count' => 0,
                    'status'      => 'available',
                    'created_by'  => $admin->id,
                ]
            );
        }

        $this->command->info('E2E fixtures created:');
        $this->command->info("  Admin:   " . self::ADMIN_USERNAME . " / " . self::ADMIN_PASSWORD);
        $this->command->info("  Learner: " . self::LEARNER_USERNAME . " / " . self::LEARNER_PASSWORD);
        $this->command->info("  Service: {$service->slug} (ID: {$service->id})");
    }
}
