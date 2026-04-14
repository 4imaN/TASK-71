<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Admin\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuditLogViewerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'administrator', 'guard_name' => 'web']);
        $this->admin = User::factory()->create(['password' => Hash::make('Admin1!')]);
        $this->admin->assignRole('administrator');
    }

    private function createEntry(array $overrides = []): AuditLog
    {
        return AuditLog::create(array_merge([
            'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
            'actor_id'       => $this->admin->id,
            'actor_type'     => 'user',
            'action'         => 'test.action',
            'entity_type'    => 'service',
            'entity_id'      => 1,
            'occurred_at'    => now(),
        ], $overrides));
    }

    // ── AuditLogService: list() ───────────────────────────────────────────────

    public function test_list_returns_all_entries_by_default(): void
    {
        $this->createEntry();
        $this->createEntry(['action' => 'another.action']);

        $result = app(AuditLogService::class)->list();

        $this->assertGreaterThanOrEqual(2, $result->total());
    }

    public function test_list_filters_by_action_partial_match(): void
    {
        $this->createEntry(['action' => 'service.created']);
        $this->createEntry(['action' => 'user.locked']);

        $result = app(AuditLogService::class)->list(['action' => 'service']);

        $this->assertEquals(1, $result->total());
        $this->assertEquals('service.created', $result->items()[0]->action);
    }

    public function test_list_filters_by_entity_type(): void
    {
        $this->createEntry(['entity_type' => 'service']);
        $this->createEntry(['entity_type' => 'import_job']);

        $result = app(AuditLogService::class)->list(['entity_type' => 'import_job']);

        $this->assertEquals(1, $result->total());
    }

    public function test_list_filters_by_correlation_id(): void
    {
        $correlationId = (string) \Illuminate\Support\Str::uuid();
        $this->createEntry(['correlation_id' => $correlationId]);
        $this->createEntry(); // different correlation_id

        $result = app(AuditLogService::class)->list(['correlation_id' => $correlationId]);

        $this->assertEquals(1, $result->total());
    }

    public function test_list_filters_by_actor_username(): void
    {
        $other = User::factory()->create(['username' => 'uniqueactor']);
        $this->createEntry(['actor_id' => $other->id]);
        $this->createEntry(['actor_id' => $this->admin->id]);

        $result = app(AuditLogService::class)->list(['actor_username' => 'uniqueactor']);

        $this->assertEquals(1, $result->total());
    }

    public function test_list_orders_by_most_recent_first(): void
    {
        $this->createEntry(['occurred_at' => now()->subHours(2), 'action' => 'older.action']);
        $this->createEntry(['occurred_at' => now()->subMinutes(1), 'action' => 'newer.action']);

        $result = app(AuditLogService::class)->list();

        $this->assertEquals('newer.action', $result->items()[0]->action);
    }

    // ── AuditLogService: find() ───────────────────────────────────────────────

    public function test_find_returns_entry_with_actor(): void
    {
        $entry = $this->createEntry();
        $found = app(AuditLogService::class)->find($entry->id);

        $this->assertEquals($entry->id, $found->id);
        $this->assertNotNull($found->actor);
        $this->assertEquals($this->admin->username, $found->actor->username);
    }

    // ── AuditLogService: byCorrelation() ─────────────────────────────────────

    public function test_by_correlation_returns_all_matching_entries(): void
    {
        $cid = (string) \Illuminate\Support\Str::uuid();
        $this->createEntry(['correlation_id' => $cid, 'action' => 'step.one']);
        $this->createEntry(['correlation_id' => $cid, 'action' => 'step.two']);
        $this->createEntry(); // unrelated

        $chain = app(AuditLogService::class)->byCorrelation($cid);

        $this->assertCount(2, $chain);
    }

    // ── AuditLogService: entityTypes() ───────────────────────────────────────

    public function test_entity_types_returns_distinct_values(): void
    {
        $this->createEntry(['entity_type' => 'service']);
        $this->createEntry(['entity_type' => 'service']); // duplicate
        $this->createEntry(['entity_type' => 'user']);

        $types = app(AuditLogService::class)->entityTypes();

        $this->assertContains('service', $types);
        $this->assertContains('user', $types);
        $this->assertEquals(count($types), count(array_unique($types)));
    }

    // ── Sensitive data: device_fingerprint not exposed in API response ────────

    public function test_api_show_exposes_has_fingerprint_bool_not_raw_hash(): void
    {
        $entry = $this->createEntry(['device_fingerprint' => hash('sha256', 'test')]);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/v1/admin/audit-logs/{$entry->id}");

        $response->assertOk();
        // has_fingerprint boolean present
        $response->assertJsonPath('has_fingerprint', true);
        // Raw hash NOT leaked as a top-level key
        $this->assertArrayNotHasKey('device_fingerprint', $response->json());
    }

    // ── API: GET /api/v1/admin/audit-logs ─────────────────────────────────────

    public function test_api_list_returns_paginated_entries(): void
    {
        $this->createEntry();

        $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/audit-logs')
            ->assertOk()
            ->assertJsonStructure(['data', 'total', 'per_page']);
    }

    public function test_api_list_requires_administrator_role(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner)
            ->getJson('/api/v1/admin/audit-logs')
            ->assertForbidden();
    }

    public function test_api_list_supports_action_filter(): void
    {
        $this->createEntry(['action' => 'backup.completed']);
        $this->createEntry(['action' => 'user.locked']);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/audit-logs?action=backup');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('backup.completed', $data[0]['action']);
    }

    // ── API: GET /api/v1/admin/audit-logs/correlation/{id} ───────────────────

    public function test_api_by_correlation_returns_chain(): void
    {
        $cid = (string) \Illuminate\Support\Str::uuid();
        $this->createEntry(['correlation_id' => $cid]);
        $this->createEntry(['correlation_id' => $cid]);

        $this->actingAs($this->admin)
            ->getJson("/api/v1/admin/audit-logs/correlation/{$cid}")
            ->assertOk()
            ->assertJsonCount(2, 'entries');
    }
}
