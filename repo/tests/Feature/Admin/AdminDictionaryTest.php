<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\ValidateAppSession;
use App\Models\DataDictionaryType;
use App\Models\DataDictionaryValue;
use App\Models\User;
use App\Services\Admin\AdminDictionaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminDictionaryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private DataDictionaryType $type;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'administrator', 'guard_name' => 'web']);

        $this->admin = User::factory()->create([
            'password' => Hash::make('secret123'),
        ]);
        $this->admin->assignRole('administrator');

        $this->type = DataDictionaryType::create([
            'code'      => 'test_type',
            'label'     => 'Test Type',
            'is_system' => false,
        ]);
    }

    // ── Service unit tests ────────────────────────────────────────────────────

    public function test_create_value_adds_to_type(): void
    {
        $service = app(AdminDictionaryService::class);
        $value   = $service->createValue($this->type, [
            'key'   => 'alpha',
            'label' => 'Alpha Value',
        ], $this->admin);

        $this->assertInstanceOf(DataDictionaryValue::class, $value);
        $this->assertDatabaseHas('data_dictionary_values', [
            'type_id' => $this->type->id,
            'key'     => 'alpha',
            'label'   => 'Alpha Value',
        ]);
    }

    public function test_update_value_changes_label(): void
    {
        $value = DataDictionaryValue::create([
            'type_id'   => $this->type->id,
            'key'       => 'beta',
            'label'     => 'Beta Old',
            'is_active' => true,
            'sort_order'=> 0,
        ]);

        $service  = app(AdminDictionaryService::class);
        $updated  = $service->updateValue($value, ['label' => 'Beta New'], $this->admin);

        $this->assertEquals('Beta New', $updated->label);
        $this->assertDatabaseHas('data_dictionary_values', ['id' => $value->id, 'label' => 'Beta New']);
    }

    public function test_deactivate_value_sets_is_active_false(): void
    {
        $value = DataDictionaryValue::create([
            'type_id'   => $this->type->id,
            'key'       => 'gamma',
            'label'     => 'Gamma',
            'is_active' => true,
            'sort_order'=> 0,
        ]);

        $service = app(AdminDictionaryService::class);
        $result  = $service->deactivateValue($value, $this->admin);

        $this->assertFalse($result->is_active);
        $this->assertDatabaseHas('data_dictionary_values', ['id' => $value->id, 'is_active' => false]);
    }

    // ── API tests ─────────────────────────────────────────────────────────────

    public function test_api_dict_list_returns_types_with_values(): void
    {
        $response = $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->getJson('/api/v1/admin/data-dictionary');

        $response->assertOk();
        $response->assertJsonStructure(['types']);
    }

    public function test_api_dict_create_value_requires_stepup(): void
    {
        $response = $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->postJson("/api/v1/admin/data-dictionary/{$this->type->code}/values", [
                'key'   => 'delta',
                'label' => 'Delta',
            ]);

        $response->assertStatus(403);
    }

    public function test_api_dict_create_value_returns_201(): void
    {
        // Obtain step-up grant
        $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->postJson('/api/v1/admin/step-up', ['password' => 'secret123'])
            ->assertOk();

        $response = $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->postJson("/api/v1/admin/data-dictionary/{$this->type->code}/values", [
                'key'   => 'delta',
                'label' => 'Delta Value',
            ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['value' => ['id', 'key', 'label']]);
        $this->assertDatabaseHas('data_dictionary_values', ['key' => 'delta', 'label' => 'Delta Value']);
    }

    public function test_api_requires_admin_role(): void
    {
        $nonAdmin = User::factory()->create();

        $response = $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($nonAdmin)
            ->getJson('/api/v1/admin/data-dictionary');

        $response->assertStatus(403);
    }
}
