<?php

namespace Tests\Feature\Catalog;

use App\Http\Middleware\ValidateAppSession;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * HTTP tests for GET /api/v1/catalog/services/{slug}
 *
 * Covers: item 8 — payload shape, 404 behavior, visibility rules.
 */
class CatalogShowApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_returns_active_service_with_payload_shape(): void
    {
        $category = ServiceCategory::factory()->create();
        $service  = Service::factory()->create([
            'category_id' => $category->id,
            'status'      => 'active',
        ]);

        // Create an upcoming available slot
        TimeSlot::factory()->create([
            'service_id' => $service->id,
            'starts_at'  => now()->addDays(3),
            'ends_at'    => now()->addDays(3)->addHour(),
            'status'     => 'available',
            'capacity'   => 5,
        ]);

        $response = $this->getJson("/api/v1/catalog/services/{$service->slug}");

        $response->assertOk();
        $response->assertJsonStructure([
            'id', 'uuid', 'slug', 'title', 'description',
            'category', 'tags', 'audiences', 'time_slots',
        ]);
        $response->assertJsonPath('slug', $service->slug);
        $response->assertJsonPath('status', 'active');
    }

    public function test_show_returns_404_for_nonexistent_slug(): void
    {
        $response = $this->getJson('/api/v1/catalog/services/nonexistent-slug');

        $response->assertStatus(404);
    }

    public function test_show_returns_404_for_inactive_service(): void
    {
        $service = Service::factory()->create(['status' => 'inactive']);

        $response = $this->getJson("/api/v1/catalog/services/{$service->slug}");

        $response->assertStatus(404);
    }

    public function test_show_returns_404_for_draft_service(): void
    {
        $service = Service::factory()->create(['status' => 'draft']);

        $response = $this->getJson("/api/v1/catalog/services/{$service->slug}");

        $response->assertStatus(404);
    }

    public function test_show_returns_404_for_archived_service(): void
    {
        $service = Service::factory()->create(['status' => 'archived']);

        $response = $this->getJson("/api/v1/catalog/services/{$service->slug}");

        $response->assertStatus(404);
    }

    public function test_show_only_includes_future_available_slots(): void
    {
        $service = Service::factory()->create(['status' => 'active']);

        // Past slot — should not appear
        TimeSlot::factory()->create([
            'service_id' => $service->id,
            'starts_at'  => now()->subDays(1),
            'ends_at'    => now()->subDays(1)->addHour(),
            'status'     => 'available',
        ]);

        // Future cancelled slot — should not appear
        TimeSlot::factory()->create([
            'service_id' => $service->id,
            'starts_at'  => now()->addDays(5),
            'ends_at'    => now()->addDays(5)->addHour(),
            'status'     => 'cancelled',
        ]);

        // Future available slot — should appear
        $futureSlot = TimeSlot::factory()->create([
            'service_id' => $service->id,
            'starts_at'  => now()->addDays(2),
            'ends_at'    => now()->addDays(2)->addHour(),
            'status'     => 'available',
        ]);

        $response = $this->getJson("/api/v1/catalog/services/{$service->slug}");

        $response->assertOk();
        $slots = $response->json('time_slots');
        $this->assertCount(1, $slots);
        $this->assertEquals($futureSlot->id, $slots[0]['id']);
    }
}
