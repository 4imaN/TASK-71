<?php

namespace Tests\Feature\Editor;

use App\Http\Middleware\ValidateAppSession;
use App\Models\ResearchProject;
use App\Models\Service;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\Editor\ServiceEditorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * HTTP tests for editor API gaps (items 16-20).
 *
 * 16. GET /api/v1/editor/services/{id}
 * 17. POST /api/v1/editor/services/{id}/archive
 * 18. Editor service research-project management
 * 19. GET /api/v1/editor/services/{serviceId}/slots
 * 20. PUT /api/v1/editor/services/{serviceId}/slots/{slotId}
 */
class EditorApiCoverageTest extends TestCase
{
    use RefreshDatabase;

    private User $editor;
    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'administrator',  'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'content_editor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'learner',        'guard_name' => 'web']);

        $this->editor = User::factory()->create([
            'password' => Hash::make('secret123'),
        ]);
        $this->editor->assignRole('content_editor');

        $this->service = app(ServiceEditorService::class)->create($this->editor, [
            'title' => 'Editor Coverage Service',
        ]);
    }

    // ── 16. GET /api/v1/editor/services/{id} ─────────────────────────────────

    public function test_show_service_returns_detail(): void
    {
        $response = $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->editor)
            ->getJson("/api/v1/editor/services/{$this->service->id}");

        $response->assertOk();
        $response->assertJsonStructure(['service' => [
            'id', 'title', 'slug', 'status', 'category', 'tags', 'audiences',
        ]]);
        $response->assertJsonPath('service.id', $this->service->id);
    }

    public function test_show_service_returns_404_for_missing(): void
    {
        $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->editor)
            ->getJson('/api/v1/editor/services/99999')
            ->assertStatus(404);
    }

    public function test_show_service_requires_editor_role(): void
    {
        $learner = User::factory()->create();

        $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($learner)
            ->getJson("/api/v1/editor/services/{$this->service->id}")
            ->assertForbidden();
    }

    // ── 17. POST /api/v1/editor/services/{id}/archive ────────────────────────

    public function test_archive_active_service_succeeds(): void
    {
        // Publish first to get to active
        app(ServiceEditorService::class)->publish($this->service, $this->editor);

        $response = $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->editor)
            ->postJson("/api/v1/editor/services/{$this->service->id}/archive");

        $response->assertOk();
        $response->assertJsonPath('service.status', 'archived');
    }

    public function test_archive_is_idempotent_for_already_archived(): void
    {
        // Publish then archive
        app(ServiceEditorService::class)->publish($this->service, $this->editor);
        app(ServiceEditorService::class)->archive($this->service, $this->editor);

        // Archiving again is idempotent — returns 200 with status still 'archived'
        $response = $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->editor)
            ->postJson("/api/v1/editor/services/{$this->service->id}/archive");

        $response->assertOk();
        $response->assertJsonPath('service.status', 'archived');
    }

    public function test_archive_requires_editor_role(): void
    {
        $learner = User::factory()->create();

        $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($learner)
            ->postJson("/api/v1/editor/services/{$this->service->id}/archive")
            ->assertForbidden();
    }

    // ── 18. Editor service research-project management ───────────────────────

    public function test_list_research_projects_returns_empty_initially(): void
    {
        $response = $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->editor)
            ->getJson("/api/v1/editor/services/{$this->service->id}/research-projects");

        $response->assertOk();
        $response->assertJsonCount(0, 'research_projects');
    }

    public function test_attach_research_projects_links_and_returns_list(): void
    {
        $project = ResearchProject::create([
            'uuid'           => (string) Str::uuid(),
            'project_number' => 'RP-001',
            'title'          => 'Test Research Project',
            'status'         => 'active',
        ]);

        $response = $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->editor)
            ->postJson("/api/v1/editor/services/{$this->service->id}/research-projects", [
                'research_project_ids' => [$project->id],
            ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'research_projects');
        $response->assertJsonPath('research_projects.0.id', $project->id);
    }

    public function test_attach_research_projects_validates_ids(): void
    {
        $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->editor)
            ->postJson("/api/v1/editor/services/{$this->service->id}/research-projects", [
                'research_project_ids' => [99999],
            ])
            ->assertStatus(422);
    }

    public function test_detach_research_project_returns_204(): void
    {
        $project = ResearchProject::create([
            'uuid'           => (string) Str::uuid(),
            'project_number' => 'RP-002',
            'title'          => 'Detach Target',
            'status'         => 'active',
        ]);

        $this->service->researchProjects()->attach($project->id);

        $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->editor)
            ->deleteJson("/api/v1/editor/services/{$this->service->id}/research-projects/{$project->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('service_research_project_links', [
            'service_id'          => $this->service->id,
            'research_project_id' => $project->id,
        ]);
    }

    // ── 19. GET /api/v1/editor/services/{serviceId}/slots ────────────────────

    public function test_list_slots_returns_collection(): void
    {
        TimeSlot::factory()->create([
            'service_id' => $this->service->id,
            'starts_at'  => now()->addDays(1),
            'ends_at'    => now()->addDays(1)->addHour(),
        ]);

        $response = $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->editor)
            ->getJson("/api/v1/editor/services/{$this->service->id}/slots");

        $response->assertOk();
        $response->assertJsonStructure(['slots']);
        $this->assertCount(1, $response->json('slots'));
    }

    public function test_list_slots_returns_404_for_invalid_service(): void
    {
        $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->editor)
            ->getJson('/api/v1/editor/services/99999/slots')
            ->assertStatus(404);
    }

    public function test_list_slots_requires_editor_role(): void
    {
        $learner = User::factory()->create();

        $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($learner)
            ->getJson("/api/v1/editor/services/{$this->service->id}/slots")
            ->assertForbidden();
    }

    // ── 20. PUT /api/v1/editor/services/{serviceId}/slots/{slotId} ──────────

    public function test_update_slot_changes_capacity(): void
    {
        $slot = TimeSlot::factory()->create([
            'service_id' => $this->service->id,
            'starts_at'  => now()->addDays(3),
            'ends_at'    => now()->addDays(3)->addHour(),
            'capacity'   => 10,
        ]);

        $response = $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->editor)
            ->putJson("/api/v1/editor/services/{$this->service->id}/slots/{$slot->id}", [
                'capacity' => 20,
            ]);

        $response->assertOk();
        $response->assertJsonPath('slot.capacity', 20);
    }

    public function test_update_slot_returns_422_for_cancelled_slot(): void
    {
        $slot = TimeSlot::factory()->create([
            'service_id' => $this->service->id,
            'starts_at'  => now()->addDays(3),
            'ends_at'    => now()->addDays(3)->addHour(),
            'status'     => 'cancelled',
        ]);

        $response = $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->editor)
            ->putJson("/api/v1/editor/services/{$this->service->id}/slots/{$slot->id}", [
                'capacity' => 20,
            ]);

        $response->assertStatus(422);
    }

    public function test_update_slot_requires_editor_role(): void
    {
        $slot = TimeSlot::factory()->create([
            'service_id' => $this->service->id,
            'starts_at'  => now()->addDays(3),
            'ends_at'    => now()->addDays(3)->addHour(),
        ]);

        $learner = User::factory()->create();

        $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($learner)
            ->putJson("/api/v1/editor/services/{$this->service->id}/slots/{$slot->id}", [
                'capacity' => 20,
            ])
            ->assertForbidden();
    }

    // ── 22. Editor list positive path ────────────────────────────────────────

    public function test_list_services_returns_paginated_services_for_editor(): void
    {
        $response = $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->editor)
            ->getJson('/api/v1/editor/services');

        $response->assertOk();
        $response->assertJsonStructure(['data', 'total', 'per_page']);
        $this->assertGreaterThanOrEqual(1, $response->json('total'));
    }
}
