<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Services\Catalog\CatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REST surface for the service catalog.
 *
 * Filter/sort logic is fully delegated to CatalogService so that the
 * Livewire components and the REST API always behave identically.
 *
 * Supported query parameters for GET /api/v1/catalog:
 *   q           — full-text search (title + description)
 *   category_id — integer foreign key
 *   tag_ids[]   — array of tag IDs (service must have ALL)
 *   audience_id — integer foreign key
 *   price_type  — "all" | "free" | "paid"
 *   sort        — "name" | "earliest_availability" | "lowest_fee"
 *   per_page    — integer (1-100, default 20)
 */
class CatalogController extends Controller
{
    public function __construct(private readonly CatalogService $catalog) {}

    public function index(Request $request): JsonResponse
    {
        $filters = [
            'search'      => $request->string('q')->trim()->value(),
            'category_id' => $request->integer('category_id') ?: null,
            'tag_ids'     => array_filter(array_map('intval', (array) $request->input('tag_ids', []))),
            'audience_id' => $request->integer('audience_id') ?: null,
            'price_type'  => $request->input('price_type', 'all'),
            'sort'        => $request->input('sort', 'name'),
        ];

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);

        return response()->json(
            $this->catalog->browse($filters, $perPage)
        );
    }

    public function show(string $slug): JsonResponse
    {
        $service = Service::with(['category', 'tags', 'audiences', 'timeSlots' => function ($q) {
            $q->where('status', 'available')
              ->where('starts_at', '>', now())
              ->orderBy('starts_at')
              ->limit(20);
        }])
            ->where('slug', $slug)
            ->where('status', 'active')
            ->firstOrFail();

        return response()->json($service);
    }
}
