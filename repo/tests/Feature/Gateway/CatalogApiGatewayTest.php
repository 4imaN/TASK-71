<?php

namespace Tests\Feature\Gateway;

use App\Http\Livewire\Catalog\BrowseComponent;
use App\Http\Livewire\Catalog\ServiceDetailComponent;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Tag;
use App\Models\User;
use App\Services\Api\CatalogApiGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Verifies that CatalogApiGateway is the shared contract for catalog
 * operations, consumed by both BrowseComponent and the REST API.
 */
class CatalogApiGatewayTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    // ── Gateway unit-style tests ─────────────────────────────────────────────

    public function test_gateway_browse_returns_paginated_active_services(): void
    {
        Service::factory()->create(['title' => 'Active Service', 'status' => 'active']);
        Service::factory()->inactive()->create(['title' => 'Inactive Service']);

        $gateway = app(CatalogApiGateway::class);
        $result  = $gateway->browse([], perPage: 10);

        $this->assertCount(1, $result->items());
        $this->assertEquals('Active Service', $result->items()[0]->title);
    }

    public function test_gateway_browse_filters_by_search(): void
    {
        Service::factory()->create(['title' => 'Data Science Workshop', 'status' => 'active']);
        Service::factory()->create(['title' => 'Art History Seminar', 'status' => 'active']);

        $gateway = app(CatalogApiGateway::class);
        $result  = $gateway->browse(['search' => 'Data Science'], perPage: 10);

        $this->assertCount(1, $result->items());
        $this->assertEquals('Data Science Workshop', $result->items()[0]->title);
    }

    public function test_gateway_browse_filters_by_category(): void
    {
        $cat = ServiceCategory::factory()->create(['name' => 'STEM']);
        Service::factory()->create(['title' => 'In Cat', 'category_id' => $cat->id, 'status' => 'active']);
        Service::factory()->create(['title' => 'No Cat', 'category_id' => null, 'status' => 'active']);

        $gateway = app(CatalogApiGateway::class);
        $result  = $gateway->browse(['category_id' => $cat->id], perPage: 10);

        $this->assertCount(1, $result->items());
        $this->assertEquals('In Cat', $result->items()[0]->title);
    }

    public function test_gateway_toggle_favorite_creates_and_removes(): void
    {
        $service = Service::factory()->create(['status' => 'active']);
        $gateway = app(CatalogApiGateway::class);

        // First toggle → favorited
        $result = $gateway->toggleFavorite($this->user->id, $service->id);
        $this->assertTrue($result);
        $this->assertTrue($gateway->isFavorited($this->user->id, $service->id));

        // Second toggle → removed
        $result = $gateway->toggleFavorite($this->user->id, $service->id);
        $this->assertFalse($result);
        $this->assertFalse($gateway->isFavorited($this->user->id, $service->id));
    }

    public function test_gateway_get_favorite_ids_returns_correct_ids(): void
    {
        $s1 = Service::factory()->create(['status' => 'active']);
        $s2 = Service::factory()->create(['status' => 'active']);
        $s3 = Service::factory()->create(['status' => 'active']);

        $gateway = app(CatalogApiGateway::class);
        $gateway->toggleFavorite($this->user->id, $s1->id);
        $gateway->toggleFavorite($this->user->id, $s3->id);

        $ids = $gateway->getFavoriteIds($this->user->id);

        $this->assertContains($s1->id, $ids->toArray());
        $this->assertContains($s3->id, $ids->toArray());
        $this->assertNotContains($s2->id, $ids->toArray());
    }

    public function test_gateway_categories_returns_active_categories(): void
    {
        ServiceCategory::factory()->create(['name' => 'Active Cat', 'is_active' => true]);
        ServiceCategory::factory()->create(['name' => 'Inactive Cat', 'is_active' => false]);

        $gateway    = app(CatalogApiGateway::class);
        $categories = $gateway->categories();

        $this->assertCount(1, $categories);
        $this->assertEquals('Active Cat', $categories->first()->name);
    }

    public function test_gateway_tags_returns_all_tags(): void
    {
        Tag::factory()->create(['name' => 'alpha']);
        Tag::factory()->create(['name' => 'beta']);

        $gateway = app(CatalogApiGateway::class);
        $tags    = $gateway->tags();

        $this->assertCount(2, $tags);
    }

    // ── Livewire integration: BrowseComponent uses CatalogApiGateway ─────────

    public function test_browse_component_delegates_to_catalog_gateway(): void
    {
        Service::factory()->create(['title' => 'Gateway Browse Service', 'status' => 'active']);

        Livewire::test(BrowseComponent::class)
            ->assertSee('Gateway Browse Service');
    }

    public function test_browse_component_toggle_favorite_uses_gateway(): void
    {
        $service = Service::factory()->create(['status' => 'active']);

        Livewire::test(BrowseComponent::class)
            ->call('toggleFavorite', $service->id);

        $gateway = app(CatalogApiGateway::class);
        $this->assertTrue($gateway->isFavorited($this->user->id, $service->id));
    }

    // ── Parity: gateway browse matches REST ──────────────────────────────────

    public function test_gateway_browse_parity_with_rest_catalog_endpoint(): void
    {
        Service::factory()->create(['title' => 'Parity Service Alpha', 'status' => 'active']);
        Service::factory()->create(['title' => 'Parity Service Beta', 'status' => 'active']);

        // Gateway result
        $gateway       = app(CatalogApiGateway::class);
        $gatewayResult = $gateway->browse([], perPage: 20);

        // REST result
        $restResponse = $this->getJson('/api/v1/catalog/services');
        $restResponse->assertOk();

        // Both should return the same number of services
        $this->assertEquals($gatewayResult->total(), $restResponse->json('total'));
    }
}
