<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\InvalidStateTransitionException;
use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Services\Api\ReservationApiGateway;
use App\Services\Reservation\PolicyService;
use App\Services\Reservation\ReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * Learner-facing reservation REST endpoints.
 *
 * All business logic is delegated to ReservationService / PolicyService.
 * Ownership is strictly enforced — learners may only interact with
 * their own reservations.
 *
 * Routes require 'auth' middleware (see routes/api.php).
 */
class ReservationController extends Controller
{
    public function __construct(
        private readonly ReservationApiGateway $gateway,
        private readonly ReservationService    $reservationService,
        private readonly PolicyService         $policyService,
    ) {}

    // ── Index ─────────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/reservations
     *
     * Paginated list of the authenticated user's reservations.
     * Optional ?status= filter (comma-separated list of status values).
     */
    public function index(Request $request): JsonResponse
    {
        $user  = Auth::user();
        $query = Reservation::with([
                'service:id,uuid,slug,title,fee_amount,is_free',
                'timeSlot:id,starts_at,ends_at,capacity,booked_count',
            ])
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc');

        if ($request->filled('status')) {
            $statuses = array_filter(array_map('trim', explode(',', $request->string('status'))));
            $query->whereIn('status', $statuses);
        }

        return response()->json($query->paginate(15));
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/reservations/{uuid}
     *
     * Single reservation detail (ownership enforced).
     */
    public function show(string $uuid): JsonResponse
    {
        $reservation = $this->findOwned($uuid);

        $reservation->load([
            'service:id,uuid,slug,title,fee_amount,is_free,requires_manual_confirmation',
            'timeSlot:id,starts_at,ends_at,capacity,booked_count',
            'cancellationReason:id,label',
            'statusHistory',
        ]);

        return response()->json([
            'reservation'     => $reservation,
            'is_late_cancel'  => $this->policyService->isLateCancellation($reservation),
            'hours_until_slot'=> $this->policyService->hoursUntilSlot($reservation),
            'consequence'     => $this->policyService->lateCancelConsequence(),
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    /**
     * POST /api/v1/reservations
     *
     * Create a reservation for the authenticated user.
     * Body: { "time_slot_id": int }
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'time_slot_id' => ['required', 'integer', 'exists:time_slots,id'],
        ]);

        $result = $this->gateway->book(Auth::user(), $data['time_slot_id']);

        if (!$result->success) {
            return response()->json(['message' => $result->error], $result->httpStatus);
        }

        return response()->json(['reservation' => $result->reservation], $result->httpStatus);
    }

    // ── Cancel ────────────────────────────────────────────────────────────────

    /**
     * POST /api/v1/reservations/{uuid}/cancel
     *
     * Cancel a reservation. Will apply a late-cancel consequence if the
     * slot is within the policy window; the response indicates what was applied.
     */
    public function cancel(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'cancellation_reason_id' => ['nullable', 'integer'],
        ]);

        $result = $this->gateway->cancel(
            Auth::user(),
            $uuid,
            $data['cancellation_reason_id'] ?? null,
        );

        if (!$result->success) {
            return response()->json(['message' => $result->error], $result->httpStatus);
        }

        return response()->json(['reservation' => $result->reservation]);
    }

    // ── Reschedule ────────────────────────────────────────────────────────────

    /**
     * POST /api/v1/reservations/{uuid}/reschedule
     *
     * Reschedule to a different time slot for the same service.
     * Body: { "time_slot_id": int }
     */
    public function reschedule(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'time_slot_id' => ['required', 'integer', 'exists:time_slots,id'],
        ]);

        $result = $this->gateway->reschedule(Auth::user(), $uuid, $data['time_slot_id']);

        if (!$result->success) {
            return response()->json(['message' => $result->error], $result->httpStatus);
        }

        return response()->json(['reservation' => $result->reservation], $result->httpStatus);
    }

    // ── Check-in ──────────────────────────────────────────────────────────────

    /**
     * POST /api/v1/reservations/{uuid}/check-in
     *
     * Check in the authenticated learner for a confirmed reservation.
     * Timing rules enforced by PolicyService (window = [start-15min, start+10min]).
     * Returns the updated reservation; status will be 'checked_in' or
     * 'partial_attendance' (late arrival).
     */
    public function checkIn(string $uuid): JsonResponse
    {
        $reservation = $this->findOwned($uuid);

        try {
            $reservation = $this->reservationService->checkIn($reservation, Auth::user());
        } catch (InvalidStateTransitionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['reservation' => $reservation->load(['service:id,uuid,slug,title', 'timeSlot:id,starts_at,ends_at'])]);
    }

    // ── Check-out ─────────────────────────────────────────────────────────────

    /**
     * POST /api/v1/reservations/{uuid}/check-out
     *
     * Check out from a checked-in (or partial_attendance) reservation.
     */
    public function checkOut(string $uuid): JsonResponse
    {
        $reservation = $this->findOwned($uuid);

        try {
            $reservation = $this->reservationService->checkOut($reservation, Auth::user());
        } catch (InvalidStateTransitionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['reservation' => $reservation->load(['service:id,uuid,slug,title', 'timeSlot:id,starts_at,ends_at'])]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Find a reservation by UUID that belongs to the authenticated user.
     * Returns 404 if the UUID does not exist or belongs to a different user.
     */
    private function findOwned(string $uuid): Reservation
    {
        return Reservation::where('uuid', $uuid)
            ->where('user_id', Auth::id())
            ->firstOrFail();
    }
}
