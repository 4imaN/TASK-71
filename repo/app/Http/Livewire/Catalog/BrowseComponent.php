<?php

namespace App\Http\Livewire\Catalog;

use App\Services\Api\CatalogApiGateway;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Learner-facing catalog browse page.
 *
 * Filters are bound to URL query parameters so that searches are shareable
 * and the browser back button works as expected.
 *
 * Delegates to CatalogApiGateway — the same API contract consumed by
 * GET /api/v1/catalog/services — so this Livewire component is a client
 * of the REST API layer rather than calling domain services directly.
 */
#[Layout('layouts.app')]
class BrowseComponent extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'cat', except: '')]
    public string $categoryId = '';

    #[Url(as: 'tags', except: [])]
    public array $tagIds = [];

    #[Url(as: 'aud', except: '')]
    public string $audienceId = '';

    #[Url(as: 'price', except: 'all')]
    public string $priceType = 'all';

    #[Url(as: 'sort', except: 'name')]
    public string $sortBy = 'name';

    // Reset pagination to page 1 whenever any filter changes
    public function updatedSearch(): void    { $this->resetPage(); }
    public function updatedCategoryId(): void { $this->resetPage(); }
    public function updatedTagIds(): void     { $this->resetPage(); }
    public function updatedAudienceId(): void { $this->resetPage(); }
    public function updatedPriceType(): void  { $this->resetPage(); }
    public function updatedSortBy(): void     { $this->resetPage(); }

    /** Clear all active filters and return to page 1. */
    public function resetFilters(): void
    {
        $this->reset('search', 'categoryId', 'tagIds', 'audienceId', 'priceType', 'sortBy');
        $this->resetPage();
    }

    /**
     * Toggle the favorite state for a service card.
     *
     * Delegates to CatalogApiGateway — the same contract consumed by
     * POST/DELETE /api/v1/user/favorites/{service_id}.
     */
    public function toggleFavorite(int $serviceId, CatalogApiGateway $gateway): void
    {
        $gateway->toggleFavorite(Auth::id(), $serviceId);
    }

    public function render(CatalogApiGateway $gateway): \Illuminate\View\View
    {
        $filters = [
            'search'      => $this->search,
            'category_id' => $this->categoryId ?: null,
            'tag_ids'     => $this->tagIds,
            'audience_id' => $this->audienceId ?: null,
            'price_type'  => $this->priceType,
            'sort'        => $this->sortBy,
        ];

        $services    = $gateway->browse($filters, perPage: 12);
        $categories  = $gateway->categories();
        $tags        = $gateway->tags();
        $audiences   = $gateway->audiences();
        $favoriteIds = $gateway->getFavoriteIds(Auth::id());

        $hasActiveFilters = $this->search !== ''
            || $this->categoryId !== ''
            || $this->tagIds !== []
            || $this->audienceId !== ''
            || $this->priceType !== 'all';

        return view('livewire.catalog.browse', compact(
            'services',
            'categories',
            'tags',
            'audiences',
            'favoriteIds',
            'hasActiveFilters'
        ));
    }
}
