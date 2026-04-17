<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\ValidateAppSession;
use App\Models\SystemConfig;
use App\Models\User;
use App\Services\Admin\AdminConfigService;
use App\Services\Admin\StepUpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminConfigTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'administrator', 'guard_name' => 'web']);

        $this->admin = User::factory()->create([
            'password' => Hash::make('secret123'),
        ]);
        $this->admin->assignRole('administrator');
    }

    // ── AdminConfigService unit tests ─────────────────────────────────────────

    public function test_allGrouped_returns_four_groups(): void
    {
        $service = app(AdminConfigService::class);
        $grouped = $service->allGrouped();

        $this->assertArrayHasKey('reservation',   $grouped);
        $this->assertArrayHasKey('auth',          $grouped);
        $this->assertArrayHasKey('import',        $grouped);
        $this->assertArrayHasKey('login_anomaly', $grouped);
    }

    public function test_update_changes_value_and_forgets_cache(): void
    {
        SystemConfig::updateOrCreate(
            ['key' => 'pending_reservation_expiry_minutes'],
            ['value' => '30', 'type' => 'integer', 'description' => 'Expiry minutes']
        );

        // Prime the cache
        Cache::put('sysconfig:pending_reservation_expiry_minutes', '30', 300);

        $service = app(AdminConfigService::class);
        $service->update('pending_reservation_expiry_minutes', 60, $this->admin);

        $this->assertDatabaseHas('system_config', [
            'key'   => 'pending_reservation_expiry_minutes',
            'value' => '60',
        ]);

        $this->assertFalse(Cache::has('sysconfig:pending_reservation_expiry_minutes'));
    }

    public function test_update_rejects_unknown_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(AdminConfigService::class)->update('unknown_key_xyz', 'value', $this->admin);
    }

    public function test_update_bulk_validates_values(): void
    {
        SystemConfig::updateOrCreate(
            ['key' => 'pending_reservation_expiry_minutes'],
            ['value' => '30', 'type' => 'integer', 'description' => 'Expiry minutes']
        );

        $this->expectException(ValidationException::class);

        // 'not_a_number' is invalid for an integer field
        app(AdminConfigService::class)->updateBulk(
            ['pending_reservation_expiry_minutes' => 'not_a_number'],
            $this->admin
        );
    }

    // ── StepUpService tests (via HTTP for proper session context) ──────────────

    public function test_stepup_verify_returns_true_for_correct_password(): void
    {
        $response = $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->postJson('/api/v1/admin/step-up', ['password' => 'secret123']);

        $response->assertOk();
        $response->assertJsonFragment(['message' => 'Step-up verified. Grant valid for 15 minutes.']);
    }

    public function test_stepup_verify_returns_false_for_wrong_password(): void
    {
        $response = $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->postJson('/api/v1/admin/step-up', ['password' => 'wrongpassword']);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'Incorrect password.']);
    }

    public function test_stepup_is_granted_after_verify(): void
    {
        SystemConfig::updateOrCreate(
            ['key' => 'pending_reservation_expiry_minutes'],
            ['value' => '30', 'type' => 'integer', 'description' => 'Expiry minutes']
        );

        $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->postJson('/api/v1/admin/step-up', ['password' => 'secret123'])
            ->assertOk();

        // Protected endpoint should succeed with a valid step-up grant
        $response = $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->putJson('/api/v1/admin/system-config/pending_reservation_expiry_minutes', ['value' => 45]);

        $response->assertOk();
        $response->assertJsonStructure(['config' => ['key', 'value']]);
        $response->assertJsonPath('config.key', 'pending_reservation_expiry_minutes');
        $response->assertJsonPath('config.value', '45');

        // Verify persisted change
        $this->assertDatabaseHas('system_config', [
            'key'   => 'pending_reservation_expiry_minutes',
            'value' => '45',
        ]);
    }

    public function test_stepup_is_not_granted_when_expired(): void
    {
        // Manually inject an expired step-up timestamp into the session
        $expiredTs = now()->subMinutes(18)->toIso8601String();

        $response = $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->withSession([StepUpService::SESSION_KEY => $expiredTs])
            ->putJson('/api/v1/admin/system-config/pending_reservation_expiry_minutes', ['value' => 30]);

        // Should return 403 because the grant is expired
        $response->assertStatus(403);
    }

    // ── API tests ─────────────────────────────────────────────────────────────

    public function test_api_system_config_list_returns_groups(): void
    {
        $response = $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->getJson('/api/v1/admin/system-config');

        $response->assertOk();
        $response->assertJsonStructure(['groups' => ['reservation', 'auth', 'import', 'login_anomaly']]);
    }

    public function test_api_config_update_without_stepup_returns_403(): void
    {
        $response = $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->putJson('/api/v1/admin/system-config/pending_reservation_expiry_minutes', ['value' => 60]);

        $response->assertStatus(403);
    }

    public function test_api_config_update_with_stepup_returns_200(): void
    {
        SystemConfig::updateOrCreate(
            ['key' => 'pending_reservation_expiry_minutes'],
            ['value' => '30', 'type' => 'integer', 'description' => 'Expiry minutes']
        );

        // Step 1: Establish step-up grant
        $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->postJson('/api/v1/admin/step-up', ['password' => 'secret123'])
            ->assertOk();

        // Step 2: Make the protected config update
        $response = $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->putJson('/api/v1/admin/system-config/pending_reservation_expiry_minutes', ['value' => 60]);

        $response->assertOk();
        $response->assertJsonStructure(['config' => ['key', 'value']]);
    }

    public function test_api_step_up_with_wrong_password_returns_422(): void
    {
        $response = $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->postJson('/api/v1/admin/step-up', ['password' => 'badpassword']);

        $response->assertStatus(422);
    }

    public function test_api_requires_admin_role(): void
    {
        $nonAdmin = User::factory()->create();

        $response = $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($nonAdmin)
            ->getJson('/api/v1/admin/system-config');

        $response->assertStatus(403);
    }
}
