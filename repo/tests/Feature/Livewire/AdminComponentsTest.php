<?php

namespace Tests\Feature\Livewire;

use App\Http\Livewire\Admin\AuditLogComponent;
use App\Http\Livewire\Admin\BackupComponent;
use App\Http\Livewire\Admin\DataDictionaryComponent;
use App\Http\Livewire\Admin\FormRulesComponent;
use App\Http\Livewire\Admin\PolicyConfigComponent;
use App\Http\Livewire\Admin\UserManagementComponent;
use App\Models\DataDictionaryType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Livewire component tests for admin-facing components (item 24).
 *
 * Covers rendering, filter state, and step-up gating for:
 *   - UserManagementComponent
 *   - PolicyConfigComponent
 *   - DataDictionaryComponent
 *   - FormRulesComponent
 *   - AuditLogComponent
 *   - BackupComponent
 */
class AdminComponentsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'administrator', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'learner',       'guard_name' => 'web']);

        $this->admin = User::factory()->create([
            'password' => Hash::make('AdminTest1!'),
        ]);
        $this->admin->assignRole('administrator');
    }

    // ── UserManagementComponent ──────────────────────────────────────────────

    public function test_user_management_renders_with_user_list(): void
    {
        $target = User::factory()->create(['status' => 'active']);

        Livewire::actingAs($this->admin)
            ->test(UserManagementComponent::class)
            ->assertStatus(200)
            ->assertSee('User Management')
            ->assertSee($target->username);
    }

    public function test_user_management_search_filters_users(): void
    {
        $target = User::factory()->create(['username' => 'searchable_xyz']);

        Livewire::actingAs($this->admin)
            ->test(UserManagementComponent::class)
            ->set('search', 'searchable_xyz')
            ->assertSee('searchable_xyz');
    }

    public function test_user_management_step_up_modal_shows_on_write_action(): void
    {
        $target = User::factory()->create(['status' => 'active']);

        Livewire::actingAs($this->admin)
            ->test(UserManagementComponent::class)
            ->call('lockUser', $target->id)
            ->assertSet('showStepUp', true);
    }

    // ── PolicyConfigComponent ────────────────────────────────────────────────

    public function test_policy_config_renders(): void
    {
        Livewire::actingAs($this->admin)
            ->test(PolicyConfigComponent::class)
            ->assertStatus(200)
            ->assertSee('Policy Configuration');
    }

    // ── DataDictionaryComponent ──────────────────────────────────────────────

    public function test_data_dictionary_renders_with_types(): void
    {
        DataDictionaryType::create([
            'code'      => 'test_comp',
            'label'     => 'Test Component Type',
            'is_system' => false,
        ]);

        Livewire::actingAs($this->admin)
            ->test(DataDictionaryComponent::class)
            ->assertStatus(200)
            ->assertSee('Data Dictionary')
            ->assertSee('Test Component Type');
    }

    // ── FormRulesComponent ───────────────────────────────────────────────────

    public function test_form_rules_renders(): void
    {
        Livewire::actingAs($this->admin)
            ->test(FormRulesComponent::class)
            ->assertStatus(200)
            ->assertSee('Form Rules');
    }

    // ── AuditLogComponent ────────────────────────────────────────────────────

    public function test_audit_log_renders(): void
    {
        Livewire::actingAs($this->admin)
            ->test(AuditLogComponent::class)
            ->assertStatus(200)
            ->assertSee('Audit Log');
    }

    public function test_audit_log_filter_by_action(): void
    {
        Livewire::actingAs($this->admin)
            ->test(AuditLogComponent::class)
            ->set('filterAction', 'auth.login')
            ->assertStatus(200);
    }

    // ── BackupComponent ──────────────────────────────────────────────────────

    public function test_backup_renders(): void
    {
        Livewire::actingAs($this->admin)
            ->test(BackupComponent::class)
            ->assertStatus(200)
            ->assertSee('Backup');
    }
}
