<?php

namespace App\Services\Api;

use App\Services\Catalog\CatalogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * API gateway for the learner-facing catalog surface.
 *
 * This class is the shared contract for all catalog read operations.
 * Both the REST API surface (CatalogController, UserController) and the
 * Livewire surface (BrowseComponent, ServiceDetailComponent) delegate
 * through this gateway so that filter/sort logic, favorites, and
 * recent-view tracking are never duplicated across presentation layers.
 *
 * Mirrors the contract of:
 *   GET  /api/v1/catalog/services
 *   POST /api/v1/user/favorites/{service_id}
 *   DELETE /api/v1/user/favorites/{service_id}
 */
class CatalogApiGateway
{
    public function __construct(
        private readonly CatalogService $catalogService,
    ) {}

    // ── Browse ───────────────────────────────────────────────────────────────

    /**
     * Browse the active service catalog with optional filters and sorting.
     *
     * Mirrors the contract of GET /api/v1/catalog/services.
     */
    public function browse(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->catalogService->browse($filters, $perPage);
    }

    // ── Favorites ────────────────────────────────────────────────────────────

    /**
     * Toggle a service favorite for the given user.
     *
     * @return bool true → now favorited, false → removed
     */
    public function toggleFavorite(int $userId, int $serviceId): bool
    {
        return $this->catalogService->toggleFavorite($userId, $serviceId);
    }

    /**
     * Check whether the user has favorited a specific service.
     */
    public function isFavorited(int $userId, int $serviceId): bool
    {
        return $this->catalogService->isFavorited($userId, $serviceId);
    }

    /**
     * Return all service IDs that a user has favorited.
     */
    public function getFavoriteIds(int $userId): Collection
    {
        return $this->catalogService->getFavoriteIds($userId);
    }

    // ── Recent views ─────────────────────────────────────────────────────────

    /**
     * Record (or refresh) a recent view for a user+service pair.
     */
    public function recordRecentView(int $userId, int $serviceId): void
    {
        $this->catalogService->recordRecentView($userId, $serviceId);
    }

    // ── Reference data ───────────────────────────────────────────────────────

    /** All active categories ordered by sort_order then name. */
    public function categories(): Collection
    {
        return $this->catalogService->categories();
    }

    /** All tags ordered alphabetically. */
    public function tags(): Collection
    {
        return $this->catalogService->tags();
    }

    /** All active audiences ordered by sort_order then label. */
    public function audiences(): Collection
    {
        return $this->catalogService->audiences();
    }
}
