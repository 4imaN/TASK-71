<?php

namespace Tests\Feature\Gateway;

use App\Models\SystemConfig;
use App\Models\User;
use App\Services\Admin\StepUpService;
use App\Services\Api\AdminConfigApiGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Verifies that AdminConfigApiGateway is the shared contract for admin
 * system-configuration, consumed by both PolicyConfigComponent and the
 * REST Admin\ConfigController.
 */
class AdminConfigApiGatewayTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'administrator', 'guard_name' => 'web']);
        $this->admin = User::factory()->create(['password' => Hash::make('AdminPass1!')]);
        $this->admin->assignRole('administrator');
        $this->actingAs($this->admin);
    }

    // ── Read ─────────────────────────────────────────────────────────────────

    public function test_all_grouped_returns_grouped_config(): void
    {
        // Seed a known config key
        SystemConfig::create([
            'key'   => 'pending_reservation_expiry_minutes',
            'value' => '30',
            'type'  => 'integer',
        ]);

        $gateway = app(AdminConfigApiGateway::class);
        $grouped = $gateway->allGrouped();

        $this->assertArrayHasKey('reservation', $grouped);
        $this->assertIsArray($grouped['reservation']);
    }

    public function test_all_grouped_includes_all_group_keys(): void
    {
        $gateway = app(AdminConfigApiGateway::class);
        $grouped = $gateway->allGrouped();

        $this->assertArrayHasKey('reservation', $grouped);
        $this->assertArrayHasKey('auth', $grouped);
        $this->assertArrayHasKey('import', $grouped);
        $this->assertArrayHasKey('login_anomaly', $grouped);
    }

    // ── Write ────────────────────────────────────────────────────────────────

    public function test_update_bulk_succeeds_with_valid_data(): void
    {
        SystemConfig::create([
            'key'   => 'pending_reservation_expiry_minutes',
            'value' => '30',
            'type'  => 'integer',
        ]);

        $gateway = app(AdminConfigApiGateway::class);
        $result  = $gateway->updateBulk([
            'pending_reservation_expiry_minutes' => '60',
        ], $this->admin);

        $this->assertTrue($result->success);

        // Verify the value was written
        $config = SystemConfig::where('key', 'pending_reservation_expiry_minutes')->first();
        $this->assertEquals('60', $config->value);
    }

    public function test_update_bulk_returns_failure_for_unknown_key(): void
    {
        $gateway = app(AdminConfigApiGateway::class);
        $result  = $gateway->updateBulk([
            'nonexistent_key' => 'value',
        ], $this->admin);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Unknown', $result->error);
    }

    public function test_update_bulk_generates_audit_log(): void
    {
        SystemConfig::create([
            'key'   => 'idle_timeout_minutes',
            'value' => '30',
            'type'  => 'integer',
        ]);

        $gateway = app(AdminConfigApiGateway::class);
        $gateway->updateBulk(['idle_timeout_minutes' => '45'], $this->admin);

        $this->assertDatabaseHas('audit_logs', [
            'action'      => 'admin.config_updated',
            'actor_id'    => $this->admin->id,
            'entity_type' => 'system_config',
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    public function test_known_keys_returns_non_empty_array(): void
    {
        $gateway = app(AdminConfigApiGateway::class);
        $keys    = $gateway->knownKeys();

        $this->assertNotEmpty($keys);
        $this->assertContains('pending_reservation_expiry_minutes', $keys);
    }

    public function test_groups_returns_expected_structure(): void
    {
        $gateway = app(AdminConfigApiGateway::class);
        $groups  = $gateway->groups();

        $this->assertArrayHasKey('reservation', $groups);
        $this->assertArrayHasKey('auth', $groups);
    }

    public function test_validation_rules_returns_per_key_rules(): void
    {
        $gateway = app(AdminConfigApiGateway::class);
        $rules   = $gateway->validationRules();

        $this->assertArrayHasKey('pending_reservation_expiry_minutes', $rules);
        $this->assertIsArray($rules['pending_reservation_expiry_minutes']);
    }

    // ── Parity: gateway read matches REST ────────────────────────────────────

    public function test_gateway_read_parity_with_rest_config_endpoint(): void
    {
        $gateway = app(AdminConfigApiGateway::class);
        $grouped = $gateway->allGrouped();

        $restResponse = $this->getJson('/api/v1/admin/system-config');
        $restResponse->assertOk();

        $restGroups = $restResponse->json('groups');

        // Both should have the same group keys
        $this->assertEquals(array_keys($grouped), array_keys($restGroups));
    }
}
