<?php

namespace Tests\Feature\Editor;

use App\Exceptions\InvalidStateTransitionException;
use App\Http\Middleware\ValidateAppSession;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Tag;
use App\Models\TargetAudience;
use App\Models\User;
use App\Services\Editor\ServiceEditorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ServiceEditorTest extends TestCase
{
    use RefreshDatabase;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'content_editor', 'guard_name' => 'web']);

        $this->editor = User::factory()->create();
        $this->editor->assignRole('content_editor');
    }

    // ── ServiceEditorService unit tests ───────────────────────────────────────

    public function test_create_produces_draft_service(): void
    {
        $service = app(ServiceEditorService::class)->create($this->editor, [
            'title' => 'My Research Service',
        ]);

        $this->assertInstanceOf(Service::class, $service);
        $this->assertEquals('draft', $service->status);
        $this->assertDatabaseHas('services', ['id' => $service->id, 'status' => 'draft']);
    }

    public function test_create_generates_slug_from_title(): void
    {
        $service = app(ServiceEditorService::class)->create($this->editor, [
            'title' => 'My Research Service',
        ]);

        $this->assertEquals('my-research-service', $service->slug);
    }

    public function test_create_generates_unique_slug_when_collision(): void
    {
        $svc1 = app(ServiceEditorService::class)->create($this->editor, [
            'title' => 'My Research Service',
        ]);
        $svc2 = app(ServiceEditorService::class)->create($this->editor, [
            'title' => 'My Research Service',
        ]);

        $this->assertEquals('my-research-service', $svc1->slug);
        $this->assertEquals('my-research-service-2', $svc2->slug);
    }

    public function test_create_syncs_tags(): void
    {
        $tag1 = Tag::factory()->create();
        $tag2 = Tag::factory()->create();

        $service = app(ServiceEditorService::class)->create($this->editor, [
            'title'   => 'Tagged Service',
            'tag_ids' => [$tag1->id, $tag2->id],
        ]);

        $tagIds = $service->tags()->pluck('tags.id')->toArray();
        $this->assertContains($tag1->id, $tagIds);
        $this->assertContains($tag2->id, $tagIds);
    }

    public function test_create_syncs_audiences(): void
    {
        $audience = TargetAudience::factory()->create();

        $service = app(ServiceEditorService::class)->create($this->editor, [
            'title'        => 'Audience Service',
            'audience_ids' => [$audience->id],
        ]);

        $audienceIds = $service->audiences()->pluck('target_audiences.id')->toArray();
        $this->assertContains($audience->id, $audienceIds);
    }

    public function test_update_changes_title_and_slug(): void
    {
        $service = app(ServiceEditorService::class)->create($this->editor, [
            'title' => 'Original Title',
        ]);

        $updated = app(ServiceEditorService::class)->update($service, $this->editor, [
            'title' => 'New Title Here',
        ]);

        $this->assertEquals('New Title Here', $updated->title);
        $this->assertEquals('new-title-here', $updated->slug);
    }

    public function test_update_syncs_tags_when_tag_ids_provided(): void
    {
        $tag1 = Tag::factory()->create();
        $tag2 = Tag::factory()->create();

        $service = app(ServiceEditorService::class)->create($this->editor, [
            'title'   => 'Tag Sync Service',
            'tag_ids' => [$tag1->id],
        ]);

        app(ServiceEditorService::class)->update($service, $this->editor, [
            'tag_ids' => [$tag2->id],
        ]);

        $tagIds = $service->tags()->pluck('tags.id')->toArray();
        $this->assertNotContains($tag1->id, $tagIds);
        $this->assertContains($tag2->id, $tagIds);
    }

    public function test_publish_transitions_draft_to_active(): void
    {
        $service = app(ServiceEditorService::class)->create($this->editor, [
            'title' => 'Draft Service',
        ]);

        $published = app(ServiceEditorService::class)->publish($service, $this->editor);

        $this->assertEquals('active', $published->status);
        $this->assertDatabaseHas('services', ['id' => $service->id, 'status' => 'active']);
    }

    public function test_publish_throws_for_archived(): void
    {
        $service = Service::factory()->create(['status' => 'archived']);

        $this->expectException(InvalidStateTransitionException::class);

        app(ServiceEditorService::class)->publish($service, $this->editor);
    }

    public function test_archive_transitions_active_to_archived(): void
    {
        $service = Service::factory()->create(['status' => 'active']);

        $archived = app(ServiceEditorService::class)->archive($service, $this->editor);

        $this->assertEquals('archived', $archived->status);
        $this->assertDatabaseHas('services', ['id' => $service->id, 'status' => 'archived']);
    }

    public function test_archive_is_idempotent(): void
    {
        $service = Service::factory()->create(['status' => 'archived']);

        // Should not throw
        $result = app(ServiceEditorService::class)->archive($service, $this->editor);

        $this->assertEquals('archived', $result->status);
    }

    // ── API tests ─────────────────────────────────────────────────────────────

    public function test_api_create_returns_201(): void
    {
        $response = $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->editor)
            ->postJson('/api/v1/editor/services', [
                'title' => 'API Created Service',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('service.status', 'draft');
    }

    public function test_api_update_returns_200(): void
    {
        $service = app(ServiceEditorService::class)->create($this->editor, [
            'title' => 'Service To Update',
        ]);

        $response = $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->editor)
            ->putJson("/api/v1/editor/services/{$service->id}", [
                'title' => 'Updated Service Title',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('service.title', 'Updated Service Title');
    }

    public function test_api_publish_returns_200(): void
    {
        $service = app(ServiceEditorService::class)->create($this->editor, [
            'title' => 'Service To Publish',
        ]);

        $response = $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->editor)
            ->postJson("/api/v1/editor/services/{$service->id}/publish");

        $response->assertStatus(200);
        $response->assertJsonPath('service.status', 'active');
    }

    public function test_api_requires_editor_role(): void
    {
        $learner = User::factory()->create();

        $response = $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($learner)
            ->getJson('/api/v1/editor/services');

        $response->assertStatus(403);
    }
}
