<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\Service;
use App\Services\Catalog\CatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * Authenticated user endpoints — dashboard summary, favorites, recent views.
 *
 * All business logic is delegated to CatalogService so that the REST surface
 * stays aligned with the Livewire components. No duplicate query logic here.
 *
 * Authentication: session-based (same session as Livewire).
 * All routes in this controller require the 'auth' middleware.
 */
class UserController extends Controller
{
    public function __construct(private readonly CatalogService $catalog) {}

    // ── Dashboard ─────────────────────────────────────────────────────────────

    /**
     * Dashboard summary — mirrors LearnerDashboardComponent data exactly.
     *
     * GET /api/v1/user/dashboard
     */
    public function dashboard(): JsonResponse
    {
        $user = Auth::user();

        $upcomingReservations = Reservation::with([
            'service:id,uuid,slug,title',
            'timeSlot:id,starts_at,ends_at',
        ])
        ->where('user_id', $user->id)
        ->whereIn('status', ['pending', 'confirmed'])
        ->whereNotNull('time_slot_id')
        ->orderBy('created_at', 'desc')
        ->limit(5)
        ->get();

        $favorites   = $this->catalog->paginatedFavorites($user->id, 6)->items();
        $recentViews = $this->catalog->paginatedRecentViews($user->id, 6)->items();

        return response()->json([
            'upcoming_reservations' => $upcomingReservations,
            'favorites'             => $favorites,
            'recent_views'          => $recentViews,
            'points_balance'        => $user->pointsBalance(),
        ]);
    }

    // ── Favorites ─────────────────────────────────────────────────────────────

    /**
     * Paginated list of the authenticated user's favorited services.
     *
     * GET /api/v1/user/favorites
     */
    public function favorites(): JsonResponse
    {
        return response()->json(
            $this->catalog->paginatedFavorites(Auth::id())
        );
    }

    /**
     * Add a service to favorites (idempotent).
     *
     * POST /api/v1/user/favorites/{service_id}
     *
     * Returns 201 when newly created, 200 when it already existed.
     * Returns 404 if the service does not exist or is not active.
     */
    public function addFavorite(int $serviceId): JsonResponse
    {
        // Validate that the service is browsable (active + exists)
        $service = Service::where('id', $serviceId)
            ->where('status', 'active')
            ->firstOrFail();

        $created = $this->catalog->addFavorite(Auth::id(), $service->id);

        return response()->json([
            'favorited'  => true,
            'service_id' => $service->id,
        ], $created ? 201 : 200);
    }

    /**
     * Remove a service from favorites (idempotent).
     *
     * DELETE /api/v1/user/favorites/{service_id}
     *
     * Returns 204 in all cases (idempotent — no error if it was not favorited).
     * Returns 404 only if the service record does not exist at all.
     */
    public function removeFavorite(int $serviceId): Response
    {
        // Confirm the service exists (any status — you may have favorited it
        // before it was archived; still allow unfavoriting)
        Service::findOrFail($serviceId);

        $this->catalog->removeFavorite(Auth::id(), $serviceId);

        return response()->noContent();
    }

    // ── Recent views ──────────────────────────────────────────────────────────

    /**
     * Paginated list of the authenticated user's recently viewed services.
     *
     * GET /api/v1/user/recent-views
     */
    public function recentViews(): JsonResponse
    {
        return response()->json(
            $this->catalog->paginatedRecentViews(Auth::id())
        );
    }
}
