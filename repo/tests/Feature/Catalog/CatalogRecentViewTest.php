<?php

namespace Tests\Feature\Catalog;

use App\Http\Livewire\Catalog\ServiceDetailComponent;
use App\Http\Middleware\ValidateAppSession;
use App\Models\Service;
use App\Models\User;
use App\Models\UserRecentView;
use App\Services\Catalog\CatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CatalogRecentViewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_record_recent_view_creates_row(): void
    {
        $service = Service::factory()->create();
        $catalog = app(CatalogService::class);

        $catalog->recordRecentView($this->user->id, $service->id);

        $this->assertDatabaseHas('user_recent_views', [
            'user_id'    => $this->user->id,
            'service_id' => $service->id,
        ]);
    }

    public function test_record_recent_view_upserts_viewed_at(): void
    {
        $service = Service::factory()->create();
        $catalog = app(CatalogService::class);

        // First view
        $catalog->recordRecentView($this->user->id, $service->id);
        $first = UserRecentView::where('user_id', $this->user->id)
            ->where('service_id', $service->id)
            ->first();

        // Advance time and view again
        $this->travel(10)->minutes();
        $catalog->recordRecentView($this->user->id, $service->id);

        // Still only one row
        $this->assertDatabaseCount('user_recent_views', 1);

        $updated = UserRecentView::where('user_id', $this->user->id)
            ->where('service_id', $service->id)
            ->first();

        $this->assertTrue($updated->viewed_at->gt($first->viewed_at), 'viewed_at should be updated on second visit');
    }

    public function test_service_detail_mount_records_recent_view(): void
    {
        $service = Service::factory()->create(['status' => 'active']);

        Livewire::test(ServiceDetailComponent::class, ['slug' => $service->slug]);

        $this->assertDatabaseHas('user_recent_views', [
            'user_id'    => $this->user->id,
            'service_id' => $service->id,
        ]);
    }

    public function test_service_detail_404_for_inactive_service(): void
    {
        $service = Service::factory()->inactive()->create();

        // Bypass session-validation middleware so we reach the route handler
        $this->withoutMiddleware(ValidateAppSession::class)
             ->get(route('catalog.show', $service->slug))
             ->assertNotFound();
    }

    public function test_service_detail_shows_upcoming_time_slots(): void
    {
        $service = Service::factory()->create(['status' => 'active']);

        // Create one future slot and one past slot
        \App\Models\TimeSlot::factory()->create([
            'service_id' => $service->id,
            'starts_at'  => now()->addDays(3),
            'ends_at'    => now()->addDays(3)->addHour(),
            'status'     => 'available',
        ]);
        \App\Models\TimeSlot::factory()->past()->create([
            'service_id' => $service->id,
            'status'     => 'available',
        ]);

        Livewire::test(ServiceDetailComponent::class, ['slug' => $service->slug])
            ->assertViewHas('timeSlots', fn ($slots) => $slots->count() === 1);
    }
}
