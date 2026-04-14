<?php

namespace Tests\Feature\Catalog;

use App\Http\Livewire\Catalog\ServiceDetailComponent;
use App\Models\Service;
use App\Models\User;
use App\Models\UserFavorite;
use App\Services\Catalog\CatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CatalogFavoriteTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    // ── CatalogService unit-level ─────────────────────────────────────────────

    public function test_toggle_favorite_creates_record(): void
    {
        $service = Service::factory()->create();
        $catalog = app(CatalogService::class);

        $result = $catalog->toggleFavorite($this->user->id, $service->id);

        $this->assertTrue($result, 'toggleFavorite should return true when favoriting');
        $this->assertDatabaseHas('user_favorites', [
            'user_id'    => $this->user->id,
            'service_id' => $service->id,
        ]);
    }

    public function test_toggle_favorite_removes_existing_record(): void
    {
        $service = Service::factory()->create();
        UserFavorite::create(['user_id' => $this->user->id, 'service_id' => $service->id]);

        $catalog = app(CatalogService::class);
        $result  = $catalog->toggleFavorite($this->user->id, $service->id);

        $this->assertFalse($result, 'toggleFavorite should return false when un-favoriting');
        $this->assertDatabaseMissing('user_favorites', [
            'user_id'    => $this->user->id,
            'service_id' => $service->id,
        ]);
    }

    public function test_is_favorited_returns_correct_state(): void
    {
        $service = Service::factory()->create();
        $catalog = app(CatalogService::class);

        $this->assertFalse($catalog->isFavorited($this->user->id, $service->id));

        UserFavorite::create(['user_id' => $this->user->id, 'service_id' => $service->id]);

        $this->assertTrue($catalog->isFavorited($this->user->id, $service->id));
    }

    public function test_get_favorite_ids_returns_correct_collection(): void
    {
        $s1 = Service::factory()->create();
        $s2 = Service::factory()->create();
        $s3 = Service::factory()->create();

        UserFavorite::create(['user_id' => $this->user->id, 'service_id' => $s1->id]);
        UserFavorite::create(['user_id' => $this->user->id, 'service_id' => $s2->id]);

        $catalog = app(CatalogService::class);
        $ids     = $catalog->getFavoriteIds($this->user->id);

        $this->assertCount(2, $ids);
        $this->assertTrue($ids->contains($s1->id));
        $this->assertTrue($ids->contains($s2->id));
        $this->assertFalse($ids->contains($s3->id));
    }

    // ── Livewire component integration ────────────────────────────────────────

    public function test_service_detail_toggles_favorite_via_livewire(): void
    {
        $service = Service::factory()->create(['status' => 'active']);

        // Initially not favorited
        Livewire::test(ServiceDetailComponent::class, ['slug' => $service->slug])
            ->assertSet('isFavorited', false)
            ->call('toggleFavorite')
            ->assertSet('isFavorited', true);

        $this->assertDatabaseHas('user_favorites', [
            'user_id'    => $this->user->id,
            'service_id' => $service->id,
        ]);

        // Toggle back off
        Livewire::test(ServiceDetailComponent::class, ['slug' => $service->slug])
            ->assertSet('isFavorited', true)   // just favorited above
            ->call('toggleFavorite')
            ->assertSet('isFavorited', false);

        $this->assertDatabaseMissing('user_favorites', [
            'user_id'    => $this->user->id,
            'service_id' => $service->id,
        ]);
    }
}
