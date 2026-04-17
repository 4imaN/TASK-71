<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\ValidateAppSession;
use App\Models\ImportConflict;
use App\Models\ImportFieldMappingTemplate;
use App\Models\ImportJob;
use App\Models\User;
use App\Services\Admin\StepUpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * HTTP tests for the admin import API surface (item 14).
 *
 * GET  /api/v1/admin/import/templates
 * POST /api/v1/admin/import/templates
 * GET  /api/v1/admin/import
 * POST /api/v1/admin/import
 * GET  /api/v1/admin/import/{id}
 * POST /api/v1/admin/import/{id}/resolve
 */
class ImportApiTest extends TestCase
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

    // ── GET /import ──────────────────────────────────────────────────────────

    public function test_list_import_jobs_returns_paginated_results(): void
    {
        $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->getJson('/api/v1/admin/import')
            ->assertOk()
            ->assertJsonStructure(['data', 'total', 'per_page']);
    }

    public function test_list_import_jobs_requires_admin_role(): void
    {
        $learner = User::factory()->create();

        $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($learner)
            ->getJson('/api/v1/admin/import')
            ->assertForbidden();
    }

    // ── POST /import ─────────────────────────────────────────────────────────

    public function test_create_import_job_with_inline_content(): void
    {
        $csvContent = "username,display_name,status\njsmith,John Smith,active\n";

        $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->postJson('/api/v1/admin/import', [
                'entity_type'  => 'users',
                'file_format'  => 'csv',
                'content'      => $csvContent,
            ])
            ->assertStatus(201)
            ->assertJsonStructure(['job' => ['id', 'uuid', 'entity_type', 'status']]);
    }

    public function test_create_import_job_requires_entity_type(): void
    {
        $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->postJson('/api/v1/admin/import', [
                'file_format' => 'csv',
                'content'     => 'a,b\n1,2',
            ])
            ->assertStatus(422);
    }

    public function test_create_import_job_rejects_invalid_entity_type(): void
    {
        $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->postJson('/api/v1/admin/import', [
                'entity_type' => 'invalid_type',
                'file_format' => 'csv',
                'content'     => 'a,b\n1,2',
            ])
            ->assertStatus(422);
    }

    // ── GET /import/{id} ─────────────────────────────────────────────────────

    public function test_show_import_job_returns_detail(): void
    {
        $job = ImportJob::create([
            'uuid'          => (string) Str::uuid(),
            'entity_type'   => 'departments',
            'file_format'   => 'csv',
            'status'        => 'completed',
            'rows_total'    => 5,
            'rows_imported' => 5,
        ]);

        $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->getJson("/api/v1/admin/import/{$job->id}")
            ->assertOk()
            ->assertJsonStructure(['job']);
    }

    public function test_show_import_job_returns_404_for_missing(): void
    {
        $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->getJson('/api/v1/admin/import/99999')
            ->assertStatus(404);
    }

    // ── GET /import/templates ────────────────────────────────────────────────

    public function test_list_templates_returns_collection(): void
    {
        $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->getJson('/api/v1/admin/import/templates')
            ->assertOk()
            ->assertJsonStructure(['templates']);
    }

    // ── POST /import/templates ───────────────────────────────────────────────

    public function test_create_template_stores_and_returns_201(): void
    {
        $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->postJson('/api/v1/admin/import/templates', [
                'name'          => 'HR CSV Template',
                'entity_type'   => 'users',
                'field_mapping' => ['emp_id' => 'username', 'full_name' => 'display_name'],
            ])
            ->assertStatus(201)
            ->assertJsonStructure(['template' => ['id', 'name', 'entity_type']]);
    }

    // ── POST /import/{id}/resolve ───────────────────────────────────────────

    public function test_resolve_conflict_succeeds(): void
    {
        $job = ImportJob::create([
            'uuid'          => (string) Str::uuid(),
            'entity_type'   => 'departments',
            'file_format'   => 'csv',
            'status'        => 'needs_review',
            'rows_total'    => 1,
            'rows_imported' => 0,
        ]);

        $conflict = ImportConflict::create([
            'import_job_id'    => $job->id,
            'record_identifier' => 'DEPT-001',
            'local_record'     => ['code' => 'DEPT-001', 'name' => 'Old Name'],
            'incoming_record'  => ['code' => 'DEPT-001', 'name' => 'New Name'],
            'field_diffs'      => ['name' => ['old' => 'Old Name', 'new' => 'New Name']],
            'resolution'       => 'pending',
        ]);

        $response = $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->postJson("/api/v1/admin/import/{$job->id}/resolve", [
                'conflict_id'     => $conflict->id,
                'resolution'      => 'admin_override',
                'resolved_record' => ['code' => 'DEPT-001', 'name' => 'Admin Choice'],
            ]);

        $response->assertOk();
        $response->assertJsonStructure(['conflict' => ['id', 'resolution', 'resolved_by']]);
        $this->assertDatabaseHas('import_conflicts', [
            'id'         => $conflict->id,
            'resolution' => 'admin_override',
        ]);
    }

    public function test_resolve_conflict_returns_404_for_missing_job(): void
    {
        $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->postJson('/api/v1/admin/import/99999/resolve', [
                'conflict_id' => 1,
                'resolution'  => 'prefer_newest',
            ])
            ->assertStatus(404);
    }

    public function test_resolve_conflict_returns_422_for_invalid_resolution(): void
    {
        $job = ImportJob::create([
            'uuid'        => (string) Str::uuid(),
            'entity_type' => 'departments',
            'file_format' => 'csv',
            'status'      => 'needs_review',
        ]);

        $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->postJson("/api/v1/admin/import/{$job->id}/resolve", [
                'conflict_id' => 1,
                'resolution'  => 'invalid_strategy',
            ])
            ->assertStatus(422);
    }

    // ── auth gate ────────────────────────────────────────────────────────────

    public function test_import_endpoints_require_auth(): void
    {
        $this->getJson('/api/v1/admin/import')
            ->assertUnauthorized();
    }
}
