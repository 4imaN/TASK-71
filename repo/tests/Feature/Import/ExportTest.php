<?php

namespace Tests\Feature\Import;

use App\Http\Middleware\ValidateAppSession;
use App\Models\Service;
use App\Models\User;
use App\Services\Admin\StepUpService;
use App\Services\Import\ExportGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ExportTest extends TestCase
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

    private function seedServices(): void
    {
        Service::create([
            'uuid'   => (string) Str::uuid(),
            'slug'   => 'research-support',
            'title'  => 'Research Support',
            'status' => 'active',
            'is_free' => true,
            'fee_amount' => 0,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        Service::create([
            'uuid'   => (string) Str::uuid(),
            'slug'   => 'lab-booking',
            'title'  => 'Lab Booking',
            'status' => 'draft',
            'is_free' => false,
            'fee_amount' => 25.00,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);
    }

    // ── ExportGeneratorService unit tests ─────────────────────────────────────────

    public function test_generate_csv_for_services(): void
    {
        $this->seedServices();

        $exporter = app(ExportGeneratorService::class);
        $result   = $exporter->generate('services', 'csv', [], $this->admin);

        $this->assertArrayHasKey('content', $result);
        $this->assertArrayHasKey('filename', $result);
        $this->assertArrayHasKey('mime_type', $result);

        $this->assertEquals('text/csv', $result['mime_type']);
        $this->assertStringEndsWith('.csv', $result['filename']);
        $this->assertStringContainsString('id,slug,title', $result['content']);
        $this->assertStringContainsString('research-support', $result['content']);
        $this->assertStringContainsString('lab-booking', $result['content']);
    }

    public function test_generate_json_for_services(): void
    {
        $this->seedServices();

        $exporter = app(ExportGeneratorService::class);
        $result   = $exporter->generate('services', 'json', [], $this->admin);

        $this->assertEquals('application/json', $result['mime_type']);
        $this->assertStringEndsWith('.json', $result['filename']);

        $decoded = json_decode($result['content'], true);
        $this->assertIsArray($decoded);
        $this->assertCount(2, $decoded);
        $this->assertArrayHasKey('slug', $decoded[0]);
        $this->assertArrayHasKey('title', $decoded[0]);
    }

    public function test_generate_csv_headers_for_departments(): void
    {
        $exporter = app(ExportGeneratorService::class);
        $result   = $exporter->generate('departments', 'csv', [], $this->admin);

        $this->assertStringContainsString('id,code,name,is_active,last_updated_at', $result['content']);
    }

    public function test_generate_json_empty_result(): void
    {
        // No services in DB
        $exporter = app(ExportGeneratorService::class);
        $result   = $exporter->generate('services', 'json', [], $this->admin);

        $decoded = json_decode($result['content'], true);
        $this->assertIsArray($decoded);
        $this->assertCount(0, $decoded);
    }

    public function test_generate_csv_with_status_filter(): void
    {
        $this->seedServices();

        $exporter = app(ExportGeneratorService::class);
        $result   = $exporter->generate('services', 'csv', ['status' => 'active'], $this->admin);

        $this->assertStringContainsString('research-support', $result['content']);
        $this->assertStringNotContainsString('lab-booking', $result['content']);
    }

    public function test_generate_unknown_entity_type_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $exporter = app(ExportGeneratorService::class);
        $exporter->generate('unknown_entity', 'csv', [], $this->admin);
    }

    public function test_generate_writes_audit_log(): void
    {
        $exporter = app(ExportGeneratorService::class);
        $exporter->generate('services', 'csv', [], $this->admin);

        $this->assertDatabaseHas('audit_logs', [
            'action'      => 'export.generated',
            'actor_id'    => $this->admin->id,
            'entity_type' => 'services',
        ]);
    }

    // ── API endpoint tests ────────────────────────────────────────────────────────

    public function test_export_api_requires_step_up(): void
    {
        $response = $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->postJson('/api/v1/admin/export', [
                'entity_type' => 'services',
                'format'      => 'csv',
            ]);

        $response->assertStatus(403);
        $response->assertJsonFragment(['message' => 'Step-up required.']);
    }

    public function test_export_api_returns_csv_after_step_up(): void
    {
        $this->seedServices();

        // Establish step-up grant
        $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->postJson('/api/v1/admin/step-up', ['password' => 'secret123'])
            ->assertOk();

        $response = $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->postJson('/api/v1/admin/export', [
                'entity_type' => 'services',
                'format'      => 'csv',
            ]);

        $response->assertOk();
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    }

    public function test_export_api_returns_json_after_step_up(): void
    {
        $this->seedServices();

        $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->postJson('/api/v1/admin/step-up', ['password' => 'secret123'])
            ->assertOk();

        $response = $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->postJson('/api/v1/admin/export', [
                'entity_type' => 'services',
                'format'      => 'json',
            ]);

        $response->assertOk();
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
    }

    public function test_export_api_returns_csv_for_user_profiles(): void
    {
        $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->postJson('/api/v1/admin/step-up', ['password' => 'secret123'])
            ->assertOk();

        $response = $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->postJson('/api/v1/admin/export', [
                'entity_type' => 'user_profiles',
                'format'      => 'csv',
            ]);

        $response->assertOk();
    }

    public function test_export_api_validates_entity_type(): void
    {
        $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->postJson('/api/v1/admin/step-up', ['password' => 'secret123'])
            ->assertOk();

        $response = $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->admin)
            ->postJson('/api/v1/admin/export', [
                'entity_type' => 'invalid_type',
                'format'      => 'csv',
            ]);

        $response->assertStatus(422);
    }
}
