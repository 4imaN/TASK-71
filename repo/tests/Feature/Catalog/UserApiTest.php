<?php

namespace Tests\Feature\Catalog;

use App\Models\Reservation;
use App\Models\Service;
use App\Models\TimeSlot;
use App\Models\User;
use App\Models\UserFavorite;
use App\Models\UserRecentView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the authenticated user API endpoints:
 *   GET  /api/v1/user/dashboard
 *   GET  /api/v1/user/favorites
 *   POST /api/v1/user/favorites/{id}
 *   DELETE /api/v1/user/favorites/{id}
 *   GET  /api/v1/user/recent-views
 *
 * These endpoints use the shared CatalogService, so the assertions here
 * also confirm REST/Livewire alignment at the service layer.
 */
class UserApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    // ── Dashboard ─────────────────────────────────────────────────────────────

    public function test_dashboard_returns_expected_structure(): void
    {
        $response = $this->getJson('/api/v1/user/dashboard');

        $response->assertOk()
                 ->assertJsonStructure([
                     'upcoming_reservations',
                     'favorites',
                     'recent_views',
                     'points_balance',
                 ]);
    }

    public function test_dashboard_upcoming_reservations_are_pending_or_confirmed_only(): void
    {
        $service  = Service::factory()->create(['status' => 'active']);
        $slot     = TimeSlot::factory()->create(['service_id' => $service->id]);

        // Create a confirmed and a cancelled reservation
        Reservation::create([
            'uuid'         => (string) Str::uuid(),
            'user_id'      => $this->user->id,
            'service_id'   => $service->id,
            'time_slot_id' => $slot->id,
            'status'       => 'confirmed',
            'requested_at' => now(),
        ]);
        Reservation::create([
            'uuid'         => (string) Str::uuid(),
            'user_id'      => $this->user->id,
            'service_id'   => $service->id,
            'time_slot_id' => $slot->id,
            'status'       => 'cancelled',
            'requested_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/user/dashboard');
        $response->assertOk();

        $reservations = $response->json('upcoming_reservations');
        $this->assertCount(1, $reservations);
        $this->assertEquals('confirmed', $reservations[0]['status']);
    }

    public function test_dashboard_points_balance_is_numeric(): void
    {
        $response = $this->getJson('/api/v1/user/dashboard');
        $response->assertOk();
        $this->assertIsInt($response->json('points_balance'));
    }

    // ── Favorites list ────────────────────────────────────────────────────────

    public function test_favorites_returns_paginated_list(): void
    {
        $s1 = Service::factory()->create(['status' => 'active']);
        $s2 = Service::factory()->create(['status' => 'active']);
        UserFavorite::create(['user_id' => $this->user->id, 'service_id' => $s1->id]);
        UserFavorite::create(['user_id' => $this->user->id, 'service_id' => $s2->id]);

        $response = $this->getJson('/api/v1/user/favorites');

        $response->assertOk()
                 ->assertJsonStructure(['data', 'total', 'per_page'])
                 ->assertJsonPath('total', 2);
    }

    public function test_favorites_only_returns_current_users_records(): void
    {
        $other = User::factory()->create();
        $svc   = Service::factory()->create(['status' => 'active']);
        UserFavorite::create(['user_id' => $other->id, 'service_id' => $svc->id]);

        $response = $this->getJson('/api/v1/user/favorites');

        $response->assertOk()
                 ->assertJsonPath('total', 0);
    }

    // ── Add favorite ──────────────────────────────────────────────────────────

    public function test_add_favorite_returns_201_on_creation(): void
    {
        $service = Service::factory()->create(['status' => 'active']);

        $response = $this->postJson("/api/v1/user/favorites/{$service->id}");

        $response->assertCreated()
                 ->assertJsonPath('favorited', true)
                 ->assertJsonPath('service_id', $service->id);

        $this->assertDatabaseHas('user_favorites', [
            'user_id'    => $this->user->id,
            'service_id' => $service->id,
        ]);
    }

    public function test_add_favorite_returns_200_when_already_favorited(): void
    {
        $service = Service::factory()->create(['status' => 'active']);
        UserFavorite::create(['user_id' => $this->user->id, 'service_id' => $service->id]);

        $response = $this->postJson("/api/v1/user/favorites/{$service->id}");

        $response->assertOk()
                 ->assertJsonPath('favorited', true);

        // Still only one row
        $this->assertDatabaseCount('user_favorites', 1);
    }

    public function test_add_favorite_returns_404_for_inactive_service(): void
    {
        $service = Service::factory()->inactive()->create();

        $this->postJson("/api/v1/user/favorites/{$service->id}")
             ->assertNotFound();
    }

    public function test_add_favorite_returns_404_for_nonexistent_service(): void
    {
        $this->postJson('/api/v1/user/favorites/99999')
             ->assertNotFound();
    }

    // ── Remove favorite ───────────────────────────────────────────────────────

    public function test_remove_favorite_returns_204(): void
    {
        $service = Service::factory()->create(['status' => 'active']);
        UserFavorite::create(['user_id' => $this->user->id, 'service_id' => $service->id]);

        $this->deleteJson("/api/v1/user/favorites/{$service->id}")
             ->assertNoContent();

        $this->assertDatabaseMissing('user_favorites', [
            'user_id'    => $this->user->id,
            'service_id' => $service->id,
        ]);
    }

    public function test_remove_favorite_is_idempotent_when_not_favorited(): void
    {
        $service = Service::factory()->create(['status' => 'active']);

        // Not in favorites — should still succeed (204)
        $this->deleteJson("/api/v1/user/favorites/{$service->id}")
             ->assertNoContent();
    }

    public function test_remove_favorite_returns_404_for_nonexistent_service(): void
    {
        $this->deleteJson('/api/v1/user/favorites/99999')
             ->assertNotFound();
    }

    // ── Recent views ──────────────────────────────────────────────────────────

    public function test_recent_views_returns_paginated_list(): void
    {
        $s1 = Service::factory()->create(['status' => 'active']);
        $s2 = Service::factory()->create(['status' => 'active']);
        UserRecentView::create(['user_id' => $this->user->id, 'service_id' => $s1->id, 'viewed_at' => now()->subMinutes(5)]);
        UserRecentView::create(['user_id' => $this->user->id, 'service_id' => $s2->id, 'viewed_at' => now()]);

        $response = $this->getJson('/api/v1/user/recent-views');

        $response->assertOk()
                 ->assertJsonStructure(['data', 'total', 'per_page'])
                 ->assertJsonPath('total', 2);
    }

    public function test_recent_views_ordered_most_recent_first(): void
    {
        $older  = Service::factory()->create(['status' => 'active']);
        $newer  = Service::factory()->create(['status' => 'active']);
        UserRecentView::create(['user_id' => $this->user->id, 'service_id' => $older->id, 'viewed_at' => now()->subHour()]);
        UserRecentView::create(['user_id' => $this->user->id, 'service_id' => $newer->id, 'viewed_at' => now()]);

        $data = $this->getJson('/api/v1/user/recent-views')->json('data');

        $this->assertEquals($newer->id, $data[0]['service_id']);
        $this->assertEquals($older->id, $data[1]['service_id']);
    }

    public function test_recent_views_only_returns_current_users_records(): void
    {
        $other  = User::factory()->create();
        $svc    = Service::factory()->create(['status' => 'active']);
        UserRecentView::create(['user_id' => $other->id, 'service_id' => $svc->id, 'viewed_at' => now()]);

        $this->getJson('/api/v1/user/recent-views')
             ->assertOk()
             ->assertJsonPath('total', 0);
    }

    // ── Auth guard ────────────────────────────────────────────────────────────

    public function test_user_endpoints_require_authentication(): void
    {
        // Use a fresh, unauthenticated client
        $this->app['auth']->logout();

        foreach ([
            ['GET',    '/api/v1/user/dashboard'],
            ['GET',    '/api/v1/user/favorites'],
            ['GET',    '/api/v1/user/recent-views'],
            ['POST',   '/api/v1/user/favorites/1'],
            ['DELETE', '/api/v1/user/favorites/1'],
        ] as [$method, $path]) {
            $this->json($method, $path)->assertUnauthorized();
        }
    }
}
