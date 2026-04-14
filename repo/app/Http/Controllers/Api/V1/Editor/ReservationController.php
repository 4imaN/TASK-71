<?php

namespace App\Http\Controllers\Api\V1\Editor;

use App\Exceptions\InvalidStateTransitionException;
use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Services\Reservation\ReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Editor/operator REST endpoints for manual reservation management.
 *
 * Covers operations that only content editors and administrators can perform:
 *   - Listing pending reservations that require manual confirmation
 *   - Confirming a pending manual-review reservation (pending → confirmed)
 *   - Rejecting (cancelling) a pending or confirmed reservation on behalf of
 *     the operator
 *
 * These operations mirror the Livewire PendingConfirmationsComponent,
 * ensuring the operator workflow is accessible via the REST surface.
 *
 * Routes require 'role:content_editor|administrator' middleware.
 */
class ReservationController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservationService,
    ) {}

    // ── Pending queue ─────────────────────────────────────────────────────────

    /**
     * GET /api/v1/editor/reservations
     *
     * Paginated list of pending reservations for services that require
     * manual confirmation.  Ordered oldest-first (FIFO operator queue).
     *
     * Optional query params:
     *   ?service_id=  — filter to one service
     *   ?status=      — override default 'pending' filter (pending|confirmed|etc.)
     */
    public function index(Request $request): JsonResponse
    {
        $query = Reservation::with([
                'user:id,username,display_name,audience_type',
                'service:id,title,slug,requires_manual_confirmation',
                'timeSlot:id,starts_at,ends_at,capacity,booked_count',
            ])
            ->whereHas('service', fn ($q) => $q->where('requires_manual_confirmation', true));

        $status = $request->string('status', 'pending');
        $query->where('status', $status);

        if ($request->filled('service_id')) {
            $query->where('service_id', (int) $request->input('service_id'));
        }

        return response()->json($query->orderBy('created_at', 'asc')->paginate(20));
    }

    // ── Confirm ───────────────────────────────────────────────────────────────

    /**
     * POST /api/v1/editor/reservations/{id}/confirm
     *
     * Confirm a pending manual-review reservation (pending → confirmed).
     * Returns 422 if the reservation is not in a confirmable state.
     */
    public function confirm(int $id): JsonResponse
    {
        $reservation = Reservation::findOrFail($id);

        if ($reservation->status !== 'pending') {
            return response()->json([
                'message' => 'Reservation is not in a pending state and cannot be confirmed.',
                'status'  => $reservation->status,
            ], 422);
        }

        try {
            $reservation = $this->reservationService->confirm(
                reservation: $reservation,
                actorId:     Auth::id(),
                actorType:   'user',
            );
        } catch (InvalidStateTransitionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'reservation' => $reservation->load([
                'user:id,username,display_name',
                'service:id,title,slug',
                'timeSlot:id,starts_at,ends_at',
            ]),
        ]);
    }

    // ── Reject ────────────────────────────────────────────────────────────────

    /**
     * POST /api/v1/editor/reservations/{id}/reject
     *
     * Reject (cancel) a pending or confirmed reservation on behalf of the
     * operator.  Frees slot capacity so other learners can book it.
     *
     * Optional body: { "reason": "string" }
     * Returns 422 if the reservation is in a terminal state (checked_in,
     * checked_out, cancelled).
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $reservation = Reservation::findOrFail($id);

        if (!in_array($reservation->status, ['pending', 'confirmed'], true)) {
            return response()->json([
                'message' => 'Reservation cannot be rejected in its current state.',
                'status'  => $reservation->status,
            ], 422);
        }

        try {
            $reservation = $this->reservationService->cancel(
                reservation: $reservation,
                actor:       Auth::user(),
            );
        } catch (InvalidStateTransitionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'reservation' => $reservation->load([
                'user:id,username,display_name',
                'service:id,title,slug',
                'timeSlot:id,starts_at,ends_at',
            ]),
        ]);
    }
}
