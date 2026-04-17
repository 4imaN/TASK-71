<?php

namespace Tests\Feature\Livewire;

use App\Http\Livewire\Editor\ServiceFormComponent;
use App\Http\Livewire\Editor\ServiceListComponent;
use App\Http\Livewire\Editor\SlotListComponent;
use App\Models\Service;
use App\Models\User;
use App\Services\Editor\ServiceEditorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Livewire component tests for editor-facing components (item 24).
 *
 * Covers rendering and basic interactions for:
 *   - ServiceListComponent
 *   - ServiceFormComponent (create mode)
 *   - SlotListComponent
 */
class EditorComponentsTest extends TestCase
{
    use RefreshDatabase;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'content_editor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'administrator',  'guard_name' => 'web']);

        $this->editor = User::factory()->create([
            'password' => Hash::make('secret123'),
        ]);
        $this->editor->assignRole('content_editor');
    }

    // ── ServiceListComponent ─────────────────────────────────────────────────

    public function test_service_list_renders(): void
    {
        Livewire::actingAs($this->editor)
            ->test(ServiceListComponent::class)
            ->assertStatus(200);
    }

    public function test_service_list_shows_existing_services(): void
    {
        $service = app(ServiceEditorService::class)->create($this->editor, [
            'title' => 'Visible In List',
        ]);

        Livewire::actingAs($this->editor)
            ->test(ServiceListComponent::class)
            ->assertSee('Visible In List');
    }

    public function test_service_list_search_filters(): void
    {
        app(ServiceEditorService::class)->create($this->editor, ['title' => 'Alpha Service']);
        app(ServiceEditorService::class)->create($this->editor, ['title' => 'Beta Service']);

        Livewire::actingAs($this->editor)
            ->test(ServiceListComponent::class)
            ->set('search', 'Alpha')
            ->assertSee('Alpha Service')
            ->assertDontSee('Beta Service');
    }

    // ── ServiceFormComponent ─────────────────────────────────────────────────

    public function test_service_form_renders_in_create_mode(): void
    {
        Livewire::actingAs($this->editor)
            ->test(ServiceFormComponent::class)
            ->assertStatus(200);
    }

    // ── SlotListComponent ────────────────────────────────────────────────────

    public function test_slot_list_renders_for_valid_service(): void
    {
        $service = app(ServiceEditorService::class)->create($this->editor, [
            'title' => 'Slot Test Service',
        ]);

        Livewire::actingAs($this->editor)
            ->test(SlotListComponent::class, ['serviceId' => $service->id])
            ->assertStatus(200);
    }
}
