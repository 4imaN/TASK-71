<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\ValidateAppSession;
use App\Models\RelationshipDefinition;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * HTTP tests for the admin relationship-definition API surface (item 15).
 *
 * GET    /api/v1/admin/relationship-definitions
 * POST   /api/v1/admin/relationship-definitions
 * DELETE /api/v1/admin/relationship-definitions/{id}
 * GET    /api/v1/admin/relationship-definitions/{id}/instances
 * POST   /api/v1/admin/relationship-definitions/{id}/instances
 * DELETE /api/v1/admin/relationship-definitions/{id}/instances/{iid}
 */
class RelationshipApiTest extends TestCase
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

    // ── GET /relationship-definitions ────────────────────────────────────────

    public function test_list_definitions_returns_structure(): void
    {
        $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->getJson('/api/v1/admin/relationship-definitions')
            ->assertOk()
            ->assertJsonStructure(['definitions', 'allowed_entity_types', 'allowed_cardinalities']);
    }

    // ── POST /relationship-definitions ───────────────────────────────────────

    public function test_create_definition_returns_201(): void
    {
        $response = $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->postJson('/api/v1/admin/relationship-definitions', [
                'name'               => 'Service ↔ Department',
                'source_entity_type' => 'service',
                'target_entity_type' => 'department',
                'cardinality'        => 'many_to_many',
            ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['definition' => ['id', 'name', 'source_entity_type', 'target_entity_type']]);
        $this->assertDatabaseHas('relationship_definitions', ['name' => 'Service ↔ Department']);
    }

    public function test_create_definition_rejects_invalid_entity_type(): void
    {
        $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->postJson('/api/v1/admin/relationship-definitions', [
                'name'               => 'Bad Type',
                'source_entity_type' => 'nonexistent',
                'target_entity_type' => 'service',
            ])
            ->assertStatus(422);
    }

    // ── DELETE /relationship-definitions/{id} ────────────────────────────────

    public function test_delete_definition_deactivates(): void
    {
        $def = RelationshipDefinition::create([
            'name'               => 'To Deactivate',
            'source_entity_type' => 'service',
            'target_entity_type' => 'tag',
            'cardinality'        => 'many_to_many',
            'is_active'          => true,
            'created_by'         => $this->admin->id,
        ]);

        $response = $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->deleteJson("/api/v1/admin/relationship-definitions/{$def->id}");

        $response->assertOk();
        $this->assertDatabaseHas('relationship_definitions', ['id' => $def->id, 'is_active' => false]);
    }

    // ── GET /relationship-definitions/{id}/instances ─────────────────────────

    public function test_list_instances_returns_definition_and_instances(): void
    {
        $def = RelationshipDefinition::create([
            'name'               => 'For Instances',
            'source_entity_type' => 'service',
            'target_entity_type' => 'service',
            'cardinality'        => 'many_to_many',
            'is_active'          => true,
            'created_by'         => $this->admin->id,
        ]);

        $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->getJson("/api/v1/admin/relationship-definitions/{$def->id}/instances")
            ->assertOk()
            ->assertJsonStructure(['definition', 'instances']);
    }

    // ── POST /relationship-definitions/{id}/instances ────────────────────────

    public function test_create_instance_returns_201(): void
    {
        $def = RelationshipDefinition::create([
            'name'               => 'Service Link',
            'source_entity_type' => 'service',
            'target_entity_type' => 'service',
            'cardinality'        => 'many_to_many',
            'is_active'          => true,
            'created_by'         => $this->admin->id,
        ]);

        $s1 = Service::factory()->create();
        $s2 = Service::factory()->create();

        $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->postJson("/api/v1/admin/relationship-definitions/{$def->id}/instances", [
                'source_id' => $s1->id,
                'target_id' => $s2->id,
            ])
            ->assertStatus(201)
            ->assertJsonStructure(['instance' => ['id', 'definition_id', 'source_entity_id', 'target_entity_id']]);
    }

    public function test_create_instance_validates_entity_existence(): void
    {
        $def = RelationshipDefinition::create([
            'name'               => 'Service Link',
            'source_entity_type' => 'service',
            'target_entity_type' => 'service',
            'cardinality'        => 'many_to_many',
            'is_active'          => true,
            'created_by'         => $this->admin->id,
        ]);

        $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->postJson("/api/v1/admin/relationship-definitions/{$def->id}/instances", [
                'source_id' => 99999,
                'target_id' => 99998,
            ])
            ->assertStatus(422);
    }

    // ── DELETE /relationship-definitions/{id}/instances/{iid} ────────────────

    public function test_delete_instance_returns_204(): void
    {
        $def = RelationshipDefinition::create([
            'name'               => 'For Delete',
            'source_entity_type' => 'service',
            'target_entity_type' => 'service',
            'cardinality'        => 'many_to_many',
            'is_active'          => true,
            'created_by'         => $this->admin->id,
        ]);

        $s1 = Service::factory()->create();
        $s2 = Service::factory()->create();

        // Create via API
        $createResponse = $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->postJson("/api/v1/admin/relationship-definitions/{$def->id}/instances", [
                'source_id' => $s1->id,
                'target_id' => $s2->id,
            ])
            ->assertStatus(201);

        $instanceId = $createResponse->json('instance.id');

        $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->deleteJson("/api/v1/admin/relationship-definitions/{$def->id}/instances/{$instanceId}")
            ->assertNoContent();
    }

    // ── role guard ───────────────────────────────────────────────────────────

    public function test_relationship_endpoints_require_admin_role(): void
    {
        $nonAdmin = User::factory()->create();

        $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($nonAdmin)
            ->getJson('/api/v1/admin/relationship-definitions')
            ->assertForbidden();
    }
}
