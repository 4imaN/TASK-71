<?php

namespace Tests\Feature\Gateway;

use App\Models\Service;
use App\Models\User;
use App\Services\Api\EditorApiGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Verifies that EditorApiGateway is the shared contract for service
 * editor mutations, consumed by both ServiceFormComponent and the
 * REST Editor\ServiceController.
 */
class EditorApiGatewayTest extends TestCase
{
    use RefreshDatabase;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'content_editor', 'guard_name' => 'web']);
        $this->editor = User::factory()->create(['password' => Hash::make('EditorPass1!')]);
        $this->editor->assignRole('content_editor');
        $this->actingAs($this->editor);
    }

    // ── Create ───────────────────────────────────────────────────────────────

    public function test_create_service_returns_success_with_201(): void
    {
        $gateway = app(EditorApiGateway::class);

        $result = $gateway->createService($this->editor, [
            'title'       => 'Gateway Created Service',
            'description' => 'Created via gateway',
            'is_free'     => true,
        ]);

        $this->assertTrue($result->success);
        $this->assertEquals(201, $result->httpStatus);
        $this->assertEquals('Gateway Created Service', $result->data->title);
        $this->assertEquals('draft', $result->data->status);
        $this->assertDatabaseHas('services', ['title' => 'Gateway Created Service']);
    }

    public function test_create_service_generates_audit_log(): void
    {
        $gateway = app(EditorApiGateway::class);
        $gateway->createService($this->editor, ['title' => 'Audited Service']);

        $this->assertDatabaseHas('audit_logs', [
            'action'      => 'service.created',
            'actor_id'    => $this->editor->id,
            'entity_type' => 'service',
        ]);
    }

    // ── Update ───────────────────────────────────────────────────────────────

    public function test_update_service_returns_success(): void
    {
        $gateway = app(EditorApiGateway::class);
        $create  = $gateway->createService($this->editor, ['title' => 'Original Title']);
        $service = $create->data;

        $result = $gateway->updateService($service->id, $this->editor, [
            'title' => 'Updated Title',
        ]);

        $this->assertTrue($result->success);
        $this->assertEquals('Updated Title', $result->data->title);
    }

    public function test_update_nonexistent_service_returns_404(): void
    {
        $gateway = app(EditorApiGateway::class);
        $result  = $gateway->updateService(99999, $this->editor, ['title' => 'X']);

        $this->assertFalse($result->success);
        $this->assertEquals(404, $result->httpStatus);
    }

    // ── Publish ──────────────────────────────────────────────────────────────

    public function test_publish_service_transitions_to_active(): void
    {
        $gateway = app(EditorApiGateway::class);
        $create  = $gateway->createService($this->editor, ['title' => 'Publishable']);

        $result = $gateway->publishService($create->data->id, $this->editor);

        $this->assertTrue($result->success);
        $this->assertEquals('active', $result->data->status);
    }

    public function test_publish_archived_service_returns_failure(): void
    {
        $service = Service::factory()->create(['status' => 'archived']);

        $gateway = app(EditorApiGateway::class);
        $result  = $gateway->publishService($service->id, $this->editor);

        $this->assertFalse($result->success);
        $this->assertEquals(422, $result->httpStatus);
    }

    public function test_publish_nonexistent_returns_404(): void
    {
        $gateway = app(EditorApiGateway::class);
        $result  = $gateway->publishService(99999, $this->editor);

        $this->assertFalse($result->success);
        $this->assertEquals(404, $result->httpStatus);
    }

    // ── Archive ──────────────────────────────────────────────────────────────

    public function test_archive_service_transitions_to_archived(): void
    {
        $service = Service::factory()->create(['status' => 'active']);

        $gateway = app(EditorApiGateway::class);
        $result  = $gateway->archiveService($service->id, $this->editor);

        $this->assertTrue($result->success);
        $this->assertEquals('archived', $result->data->status);
    }

    public function test_archive_nonexistent_returns_404(): void
    {
        $gateway = app(EditorApiGateway::class);
        $result  = $gateway->archiveService(99999, $this->editor);

        $this->assertFalse($result->success);
        $this->assertEquals(404, $result->httpStatus);
    }

    // ── Reference data ───────────────────────────────────────────────────────

    public function test_get_reference_data_returns_expected_keys(): void
    {
        $gateway = app(EditorApiGateway::class);
        $data    = $gateway->getReferenceData();

        $this->assertArrayHasKey('categories', $data);
        $this->assertArrayHasKey('tags', $data);
        $this->assertArrayHasKey('audiences', $data);
        $this->assertArrayHasKey('serviceTypes', $data);
        $this->assertArrayHasKey('researchProjects', $data);
    }

    // ── Parity: gateway create matches REST ──────────────────────────────────

    public function test_gateway_create_parity_with_rest_endpoint(): void
    {
        // Gateway create
        $gateway       = app(EditorApiGateway::class);
        $gatewayResult = $gateway->createService($this->editor, [
            'title' => 'Parity Gateway Service',
        ]);

        // REST create
        $restResponse = $this->postJson('/api/v1/editor/services', [
            'title' => 'Parity REST Service',
        ]);

        // Both succeed
        $this->assertTrue($gatewayResult->success);
        $restResponse->assertStatus(201);

        // Both produce services in the database
        $this->assertDatabaseHas('services', ['title' => 'Parity Gateway Service']);
        $this->assertDatabaseHas('services', ['title' => 'Parity REST Service']);
    }
}
