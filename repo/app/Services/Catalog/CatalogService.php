<?php

namespace App\Services\Catalog;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Tag;
use App\Models\TargetAudience;
use App\Models\UserFavorite;
use App\Models\UserRecentView;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Shared service-layer logic for catalog browsing, favorites, and recent views.
 *
 * Consumed by both Livewire components and the REST API controllers so that
 * filter/sort logic is never duplicated.
 */
class CatalogService
{
    /**
     * Browse the active service catalog with optional filters and sorting.
     *
     * @param array{
     *   search?: string,
     *   category_id?: int|null,
     *   tag_ids?: int[],
     *   audience_id?: int|null,
     *   price_type?: 'all'|'free'|'paid',
     *   sort?: 'name'|'earliest_availability'|'lowest_fee',
     * } $filters
     */
    public function browse(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Service::with(['category', 'tags', 'audiences'])
            ->where('status', 'active');

        // Full-text search across title and description.
        // PostgreSQL uses ILIKE (case-insensitive); SQLite LIKE is case-insensitive
        // by default for ASCII, so we normalise with lowercase on both sides.
        if (!empty($filters['search'])) {
            $isPgsql = DB::connection()->getDriverName() === 'pgsql';
            $op      = $isPgsql ? 'ilike' : 'like';
            $term    = '%' . Str::lower(trim($filters['search'])) . '%';
            $query->where(function (Builder $q) use ($op, $term, $isPgsql) {
                if ($isPgsql) {
                    $q->where('title', $op, $term)->orWhere('description', $op, $term);
                } else {
                    // SQLite: LOWER() for column too
                    $q->whereRaw('LOWER(title) LIKE ?', [$term])
                      ->orWhereRaw('LOWER(description) LIKE ?', [$term]);
                }
            });
        }

        // Category filter
        if (!empty($filters['category_id'])) {
            $query->where('category_id', (int) $filters['category_id']);
        }

        // Tag filter — service must have every selected tag
        if (!empty($filters['tag_ids'])) {
            foreach ((array) $filters['tag_ids'] as $tagId) {
                $query->whereHas('tags', fn (Builder $q) => $q->where('tags.id', (int) $tagId));
            }
        }

        // Audience filter
        if (!empty($filters['audience_id'])) {
            $query->whereHas(
                'audiences',
                fn (Builder $q) => $q->where('target_audiences.id', (int) $filters['audience_id'])
            );
        }

        // Price filter
        $priceType = $filters['price_type'] ?? 'all';
        if ($priceType === 'free') {
            $query->where('is_free', true);
        } elseif ($priceType === 'paid') {
            $query->where('is_free', false);
        }

        // Sorting
        $sort = $filters['sort'] ?? 'name';
        match ($sort) {
            'earliest_availability' => $this->applyEarliestAvailabilitySort($query),
            'lowest_fee'            => $query->orderBy('is_free', 'desc')->orderBy('fee_amount')->orderBy('title'),
            default                 => $query->orderBy('title'),
        };

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * Add a sub-query join that surfaces the earliest upcoming available slot.
     * Services with no upcoming slots sort after those that have one.
     */
    private function applyEarliestAvailabilitySort(Builder $query): void
    {
        $query->leftJoinSub(
            DB::table('time_slots')
                ->select('service_id', DB::raw('MIN(starts_at) AS earliest_slot'))
                ->where('status', 'available')
                ->where('starts_at', '>', now())
                ->whereNull('deleted_at')
                ->groupBy('service_id'),
            'upcoming_slots',
            'upcoming_slots.service_id',
            '=',
            'services.id'
        )
        ->addSelect('services.*')
        ->orderByRaw('upcoming_slots.earliest_slot IS NULL')
        ->orderBy('upcoming_slots.earliest_slot')
        ->orderBy('services.title');
    }

    // ── Favorites ─────────────────────────────────────────────────────────────

    /**
     * Toggle a service favorite for the given user.
     *
     * @return bool  true  → service is now favorited
     *               false → favorite was removed
     */
    public function toggleFavorite(int $userId, int $serviceId): bool
    {
        $existing = UserFavorite::where('user_id', $userId)
            ->where('service_id', $serviceId)
            ->first();

        if ($existing) {
            $existing->delete();
            return false;
        }

        UserFavorite::create([
            'user_id'    => $userId,
            'service_id' => $serviceId,
        ]);

        return true;
    }

    /**
     * Check whether the user has favorited a specific service.
     */
    public function isFavorited(int $userId, int $serviceId): bool
    {
        return UserFavorite::where('user_id', $userId)
            ->where('service_id', $serviceId)
            ->exists();
    }

    /**
     * Add a favorite (idempotent — no-op if it already exists).
     *
     * @return bool  true if newly created, false if it already existed
     */
    public function addFavorite(int $userId, int $serviceId): bool
    {
        if (UserFavorite::where('user_id', $userId)->where('service_id', $serviceId)->exists()) {
            return false;
        }
        UserFavorite::create(['user_id' => $userId, 'service_id' => $serviceId]);
        return true;
    }

    /**
     * Remove a favorite (idempotent — no-op if it does not exist).
     *
     * @return bool  true if a row was deleted, false if nothing was there
     */
    public function removeFavorite(int $userId, int $serviceId): bool
    {
        return (bool) UserFavorite::where('user_id', $userId)
            ->where('service_id', $serviceId)
            ->delete();
    }

    /**
     * Return all service IDs that a user has favorited (for bulk UI state checks).
     *
     * @return Collection<int, int>
     */
    public function getFavoriteIds(int $userId): Collection
    {
        return UserFavorite::where('user_id', $userId)->pluck('service_id');
    }

    /**
     * Paginated favorites list with service details for the API surface.
     *
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function paginatedFavorites(int $userId, int $perPage = 20)
    {
        return UserFavorite::with(['service' => function ($q) {
            $q->with(['category:id,name,slug', 'tags:id,name,slug'])
              ->where('status', 'active');
        }])
        ->where('user_id', $userId)
        ->latest('created_at')
        ->paginate($perPage);
    }

    /**
     * Paginated recent views list with service details for the API surface.
     *
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function paginatedRecentViews(int $userId, int $perPage = 20)
    {
        return UserRecentView::with(['service' => function ($q) {
            $q->with(['category:id,name,slug'])
              ->where('status', 'active');
        }])
        ->where('user_id', $userId)
        ->latest('viewed_at')
        ->paginate($perPage);
    }

    // ── Recent views ──────────────────────────────────────────────────────────

    /**
     * Record (or refresh) a recent view for a user+service pair.
     * The unique constraint on (user_id, service_id) enforces one row per pair;
     * viewed_at is updated on every subsequent visit.
     */
    public function recordRecentView(int $userId, int $serviceId): void
    {
        UserRecentView::updateOrCreate(
            ['user_id' => $userId, 'service_id' => $serviceId],
            ['viewed_at' => now()]
        );
    }

    // ── Reference data ────────────────────────────────────────────────────────

    /** All active categories ordered by sort_order then name. */
    public function categories(): Collection
    {
        return ServiceCategory::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /** All tags ordered alphabetically. */
    public function tags(): Collection
    {
        return Tag::orderBy('name')->get();
    }

    /** All active audiences ordered by sort_order then label. */
    public function audiences(): Collection
    {
        return TargetAudience::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get();
    }
}
