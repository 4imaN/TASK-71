<?php

namespace App\Http\Controllers\Api\V1\Editor;

use App\Exceptions\InvalidStateTransitionException;
use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\TimeSlot;
use App\Services\Admin\DynamicValidationResolver;
use App\Services\Editor\SlotEditorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * REST endpoints for editor slot management.
 *
 * All slots are scoped to a service (serviceId route parameter).
 * All business logic is delegated to SlotEditorService.
 * Dynamic field validation rules are merged via DynamicValidationResolver.
 */
class SlotController extends Controller
{
    public function __construct(
        private readonly SlotEditorService        $slotEditorService,
        private readonly DynamicValidationResolver $resolver,
    ) {}

    // ── Index ─────────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/editor/services/{serviceId}/slots
     */
    public function index(int $serviceId): JsonResponse
    {
        $service = Service::findOrFail($serviceId);

        $slots = TimeSlot::where('service_id', $service->id)
            ->withCount([
                'reservations as pending_count'   => fn ($q) => $q->where('status', 'pending'),
                'reservations as confirmed_count' => fn ($q) => $q->where('status', 'confirmed'),
            ])
            ->orderBy('starts_at')
            ->get();

        return response()->json(['slots' => $slots]);
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    /**
     * POST /api/v1/editor/services/{serviceId}/slots
     */
    public function store(Request $request, int $serviceId): JsonResponse
    {
        $service = Service::findOrFail($serviceId);

        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at'   => ['required', 'date', 'after:starts_at'],
            'capacity'  => $this->resolver->resolve('time_slot', 'capacity', ['required', 'integer', 'min:1']),
        ]);

        $slot = $this->slotEditorService->createSlot($service, Auth::user(), $data);

        return response()->json(['slot' => $slot], 201);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    /**
     * PUT /api/v1/editor/services/{serviceId}/slots/{slotId}
     */
    public function update(Request $request, int $serviceId, int $slotId): JsonResponse
    {
        $slot = TimeSlot::where('id', $slotId)
            ->where('service_id', $serviceId)
            ->firstOrFail();

        $data = $request->validate([
            'starts_at' => ['sometimes', 'date'],
            'ends_at'   => ['sometimes', 'date'],
            'capacity'  => $this->resolver->resolve('time_slot', 'capacity', ['sometimes', 'integer', 'min:1']),
        ]);

        try {
            $slot = $this->slotEditorService->updateSlot($slot, Auth::user(), $data);
        } catch (InvalidStateTransitionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['slot' => $slot]);
    }

    // ── Cancel ────────────────────────────────────────────────────────────────

    /**
     * POST /api/v1/editor/services/{serviceId}/slots/{slotId}/cancel
     */
    public function cancel(int $serviceId, int $slotId): JsonResponse
    {
        $slot = TimeSlot::where('id', $slotId)
            ->where('service_id', $serviceId)
            ->firstOrFail();

        try {
            $slot = $this->slotEditorService->cancelSlot($slot, Auth::user());
        } catch (InvalidStateTransitionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['slot' => $slot]);
    }
}
