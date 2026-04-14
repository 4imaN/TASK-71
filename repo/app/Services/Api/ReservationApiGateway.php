<?php

namespace App\Services\Api;

use App\Exceptions\BookingFrozenException;
use App\Exceptions\EligibilityViolationException;
use App\Exceptions\InvalidStateTransitionException;
use App\Exceptions\SlotUnavailableException;
use App\Models\Reservation;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\Reservation\PolicyService;
use App\Services\Reservation\ReservationService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * API gateway for the learner-facing reservation lifecycle.
 *
 * This class is the single authoritative implementation for reservation
 * reads and mutations. Both the REST API surface (ReservationController)
 * and the Livewire surface (ServiceDetailComponent,
 * ReservationDetailComponent, ReservationListComponent) delegate through
 * this gateway — they are consumers of the same API contract.
 *
 * Callers receive a typed GatewayResult value object containing:
 *   - success flag
 *   - the resulting Reservation (on success)
 *   - a human-readable error message (on failure)
 *   - an HTTP status code hint (for REST controllers to propagate)
 *
 * Ownership of reservation UUIDs is enforced here; callers pass the acting
 * user and this gateway verifies it owns the record before any mutation.
 */
class ReservationApiGateway
{
    public function __construct(
        private readonly ReservationService $reservationService,
        private readonly PolicyService      $policyService,
    ) {}

    // ── List ──────────────────────────────────────────────────────────────────

    /**
     * Paginated list of reservations owned by $user with optional status filter.
     *
     * Mirrors the contract of GET /api/v1/reservations.
     *
     * @param User        $user      The authenticated user whose reservations to list.
     * @param string[]|null $statuses Optional array of status values to filter by.
     * @param int         $perPage   Items per page.
     */
    public function list(User $user, ?array $statuses = null, int $perPage = 15): LengthAwarePaginator
    {
        $query = Reservation::with([
                'service:id,uuid,slug,title,is_free,fee_amount',
                'timeSlot:id,starts_at,ends_at',
            ])
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc');

        if (!empty($statuses)) {
            $query->whereIn('status', $statuses);
        }

        return $query->paginate($perPage);
    }

    // ── Book ──────────────────────────────────────────────────────────────────

    /**
     * Create a new reservation for $user on $slotId.
     *
     * Mirrors the contract of POST /api/v1/reservations.
     */
    public function book(User $user, int $slotId): GatewayResult
    {
        $slot = TimeSlot::find($slotId);

        if (!$slot) {
            return GatewayResult::failure('The requested time slot does not exist.', 404);
        }

        try {
            $reservation = $this->reservationService->create($user, $slot);
        } catch (BookingFrozenException $e) {
            return GatewayResult::failure($e->getMessage(), 403);
        } catch (EligibilityViolationException $e) {
            return GatewayResult::failure($e->getMessage(), 422);
        } catch (SlotUnavailableException $e) {
            return GatewayResult::failure($e->getMessage(), 409);
        }

        $reservation->load(['service:id,uuid,slug,title', 'timeSlot:id,starts_at,ends_at']);

        return GatewayResult::success($reservation, 201);
    }

    // ── Cancel ────────────────────────────────────────────────────────────────

    /**
     * Cancel the reservation identified by $uuid, owned by $user.
     *
     * Mirrors the contract of POST /api/v1/reservations/{uuid}/cancel.
     */
    public function cancel(User $user, string $uuid, ?int $reasonId = null): GatewayResult
    {
        $reservation = Reservation::where('uuid', $uuid)
            ->where('user_id', $user->id)
            ->first();

        if (!$reservation) {
            return GatewayResult::failure('Reservation not found.', 404);
        }

        try {
            $reservation = $this->reservationService->cancel(
                reservation: $reservation,
                actor:       $user,
                reasonId:    $reasonId,
            );
        } catch (InvalidStateTransitionException $e) {
            return GatewayResult::failure($e->getMessage(), 422);
        }

        $reservation->load(['service:id,uuid,slug,title', 'timeSlot:id,starts_at,ends_at']);

        return GatewayResult::success($reservation);
    }

    // ── Reschedule ────────────────────────────────────────────────────────────

    /**
     * Reschedule $uuid (owned by $user) to $newSlotId.
     *
     * Mirrors the contract of POST /api/v1/reservations/{uuid}/reschedule.
     */
    public function reschedule(User $user, string $uuid, int $newSlotId): GatewayResult
    {
        $reservation = Reservation::where('uuid', $uuid)
            ->where('user_id', $user->id)
            ->first();

        if (!$reservation) {
            return GatewayResult::failure('Reservation not found.', 404);
        }

        $newSlot = TimeSlot::find($newSlotId);

        if (!$newSlot) {
            return GatewayResult::failure('The selected time slot does not exist.', 404);
        }

        if ($newSlot->service_id !== $reservation->service_id) {
            return GatewayResult::failure(
                'The selected slot does not belong to the same service.',
                422,
            );
        }

        try {
            $newReservation = $this->reservationService->reschedule(
                reservation: $reservation,
                newSlot:     $newSlot,
                actor:       $user,
            );
        } catch (InvalidStateTransitionException $e) {
            return GatewayResult::failure($e->getMessage(), 422);
        } catch (SlotUnavailableException $e) {
            return GatewayResult::failure($e->getMessage(), 409);
        }

        $newReservation->load(['service:id,uuid,slug,title', 'timeSlot:id,starts_at,ends_at']);

        return GatewayResult::success($newReservation, 201);
    }

    // ── Check-in ──────────────────────────────────────────────────────────────

    /**
     * Check in the reservation identified by $uuid, owned by $user.
     *
     * Mirrors the contract of POST /api/v1/reservations/{uuid}/check-in.
     * Both the REST controller and ReservationDetailComponent delegate here.
     */
    public function checkIn(User $user, string $uuid): GatewayResult
    {
        $reservation = Reservation::where('uuid', $uuid)
            ->where('user_id', $user->id)
            ->first();

        if (!$reservation) {
            return GatewayResult::failure('Reservation not found.', 404);
        }

        try {
            $reservation = $this->reservationService->checkIn($reservation, $user);
        } catch (InvalidStateTransitionException $e) {
            return GatewayResult::failure($e->getMessage(), 422);
        }

        $reservation->load(['service:id,uuid,slug,title', 'timeSlot:id,starts_at,ends_at']);

        return GatewayResult::success($reservation);
    }

    // ── Check-out ─────────────────────────────────────────────────────────────

    /**
     * Check out from the reservation identified by $uuid, owned by $user.
     *
     * Mirrors the contract of POST /api/v1/reservations/{uuid}/check-out.
     */
    public function checkOut(User $user, string $uuid): GatewayResult
    {
        $reservation = Reservation::where('uuid', $uuid)
            ->where('user_id', $user->id)
            ->first();

        if (!$reservation) {
            return GatewayResult::failure('Reservation not found.', 404);
        }

        try {
            $reservation = $this->reservationService->checkOut($reservation, $user);
        } catch (InvalidStateTransitionException $e) {
            return GatewayResult::failure($e->getMessage(), 422);
        }

        $reservation->load(['service:id,uuid,slug,title', 'timeSlot:id,starts_at,ends_at']);

        return GatewayResult::success($reservation);
    }

    // ── Policy helpers ────────────────────────────────────────────────────────

    /**
     * Expose the policy service so callers (REST + Livewire) can render
     * late-cancel warnings, check-in windows, etc. from the same source.
     */
    public function policy(): PolicyService
    {
        return $this->policyService;
    }
}
