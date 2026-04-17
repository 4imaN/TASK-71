<?php

namespace Tests\Feature\Livewire;

use App\Http\Livewire\Admin\ImportJobDetailComponent;
use App\Http\Livewire\Admin\ImportJobListComponent;
use App\Http\Livewire\Admin\RelationshipManagerComponent;
use App\Models\ImportJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Livewire component tests for admin import and relationship screens (item 7).
 *
 * Covers rendering and basic state for:
 *   - ImportJobListComponent
 *   - ImportJobDetailComponent
 *   - RelationshipManagerComponent
 */
class ImportRelationshipComponentsTest extends TestCase
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

    // ── ImportJobListComponent ────────────────────────────────────────────────

    public function test_import_job_list_renders(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ImportJobListComponent::class)
            ->assertStatus(200);
    }

    public function test_import_job_list_shows_jobs(): void
    {
        ImportJob::create([
            'uuid'        => (string) Str::uuid(),
            'entity_type' => 'departments',
            'file_format' => 'csv',
            'status'      => 'completed',
            'rows_total'  => 10,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ImportJobListComponent::class)
            ->assertSee('departments');
    }

    public function test_import_job_list_filters_by_status(): void
    {
        ImportJob::create([
            'uuid'        => (string) Str::uuid(),
            'entity_type' => 'users',
            'file_format' => 'csv',
            'status'      => 'needs_review',
        ]);

        Livewire::actingAs($this->admin)
            ->test(ImportJobListComponent::class)
            ->set('statusFilter', 'needs_review')
            ->assertSee('needs_review');
    }

    // ── ImportJobDetailComponent ──────────────────────────────────────────────

    public function test_import_job_detail_renders(): void
    {
        $job = ImportJob::create([
            'uuid'        => (string) Str::uuid(),
            'entity_type' => 'departments',
            'file_format' => 'csv',
            'status'      => 'completed',
            'rows_total'  => 5,
            'rows_imported' => 5,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ImportJobDetailComponent::class, ['jobId' => $job->id])
            ->assertStatus(200)
            ->assertSee('departments');
    }

    public function test_import_job_detail_shows_conflict_filter_tabs(): void
    {
        $job = ImportJob::create([
            'uuid'        => (string) Str::uuid(),
            'entity_type' => 'users',
            'file_format' => 'csv',
            'status'      => 'needs_review',
        ]);

        Livewire::actingAs($this->admin)
            ->test(ImportJobDetailComponent::class, ['jobId' => $job->id])
            ->assertSet('conflictFilter', 'pending');
    }

    // ── RelationshipManagerComponent ─────────────────────────────────────────

    public function test_relationship_manager_renders(): void
    {
        Livewire::actingAs($this->admin)
            ->test(RelationshipManagerComponent::class)
            ->assertStatus(200);
    }

    public function test_relationship_manager_shows_definition_form_toggle(): void
    {
        Livewire::actingAs($this->admin)
            ->test(RelationshipManagerComponent::class)
            ->assertSet('showDefinitionForm', false);
    }
}
