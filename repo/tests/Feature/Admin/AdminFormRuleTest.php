<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\ValidateAppSession;
use App\Models\FormRule;
use App\Models\User;
use App\Services\Admin\AdminFormRuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminFormRuleTest extends TestCase
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

    // ── Service unit tests ────────────────────────────────────────────────────

    public function test_upsert_creates_new_rule(): void
    {
        $service = app(AdminFormRuleService::class);
        $rule    = $service->upsert([
            'entity_type' => 'user',
            'field_name'  => 'email',
            'rules'       => ['required' => true, 'max_length' => 255],
            'is_active'   => true,
        ], $this->admin);

        $this->assertInstanceOf(FormRule::class, $rule);
        $this->assertDatabaseHas('form_rules', [
            'entity_type' => 'user',
            'field_name'  => 'email',
        ]);
    }

    public function test_upsert_updates_existing_rule(): void
    {
        FormRule::create([
            'entity_type' => 'user',
            'field_name'  => 'phone',
            'rules'       => ['required' => true],
            'is_active'   => true,
        ]);

        $service = app(AdminFormRuleService::class);
        $rule    = $service->upsert([
            'entity_type' => 'user',
            'field_name'  => 'phone',
            'rules'       => ['required' => true, 'min_length' => 10],
            'is_active'   => true,
        ], $this->admin);

        $this->assertEquals(10, $rule->rules['min_length']);
        $this->assertEquals(1, FormRule::where('entity_type', 'user')->where('field_name', 'phone')->count());
    }

    public function test_upsert_invalidates_resolver_cache(): void
    {
        // Prime the cache entry
        Cache::put('form_rule:user:email', ['required' => true], 300);

        $service = app(AdminFormRuleService::class);
        $service->upsert([
            'entity_type' => 'user',
            'field_name'  => 'email',
            'rules'       => ['required' => true],
            'is_active'   => true,
        ], $this->admin);

        $this->assertFalse(Cache::has('form_rule:user:email'));
    }

    public function test_deactivate_sets_is_active_false(): void
    {
        $rule = FormRule::create([
            'entity_type' => 'reservation',
            'field_name'  => 'notes',
            'rules'       => [],
            'is_active'   => true,
        ]);

        $service = app(AdminFormRuleService::class);
        $result  = $service->deactivate($rule, $this->admin);

        $this->assertFalse($result->is_active);
        $this->assertDatabaseHas('form_rules', ['id' => $rule->id, 'is_active' => false]);
    }

    // ── API tests ─────────────────────────────────────────────────────────────

    public function test_api_list_rules_returns_200(): void
    {
        $response = $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->getJson('/api/v1/admin/form-rules');

        $response->assertOk();
        $response->assertJsonStructure(['rules']);
    }

    public function test_api_create_rule_requires_stepup(): void
    {
        $response = $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->postJson('/api/v1/admin/form-rules', [
                'entity_type'  => 'user',
                'field_name'   => 'username',
                'rules'        => ['required' => true],
            ]);

        $response->assertStatus(403);
    }

    public function test_api_create_rule_returns_201(): void
    {
        // Obtain step-up grant
        $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->postJson('/api/v1/admin/step-up', ['password' => 'secret123'])
            ->assertOk();

        $response = $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->postJson('/api/v1/admin/form-rules', [
                'entity_type' => 'user',
                'field_name'  => 'username',
                'rules'       => ['required' => true, 'min_length' => 3],
                'is_active'   => true,
            ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['rule' => ['id', 'entity_type', 'field_name']]);
    }

    public function test_api_deactivate_rule_returns_204(): void
    {
        $rule = FormRule::create([
            'entity_type' => 'service',
            'field_name'  => 'title',
            'rules'       => ['required' => true],
            'is_active'   => true,
        ]);

        // Obtain step-up grant
        $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->postJson('/api/v1/admin/step-up', ['password' => 'secret123'])
            ->assertOk();

        $response = $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->deleteJson("/api/v1/admin/form-rules/{$rule->id}");

        $response->assertStatus(204);
        $this->assertDatabaseHas('form_rules', ['id' => $rule->id, 'is_active' => false]);
    }

    public function test_api_requires_admin_role(): void
    {
        $nonAdmin = User::factory()->create();

        $response = $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($nonAdmin)
            ->getJson('/api/v1/admin/form-rules');

        $response->assertStatus(403);
    }
}
