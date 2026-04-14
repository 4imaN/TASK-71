<?php

namespace App\Http\Livewire\Reservation;

use App\Models\Reservation;
use App\Models\TimeSlot;
use App\Services\Api\ReservationApiGateway;
use App\Services\Reservation\PolicyService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Reservation detail: shows status, timeslot, late-cancel warning,
 * reschedule slot picker, cancel confirmation modal,
 * and check-in / check-out actions.
 *
 * Owned-by-user check happens in mount() — any other user gets 404.
 */
#[Layout('layouts.app')]
class ReservationDetailComponent extends Component
{
    public Reservation $reservation;

    // Cancel flow
    public bool   $showCancelModal = false;
    public string $cancelError     = '';

    // Reschedule flow
    public bool   $showReschedule   = false;
    public ?int   $selectedSlotId   = null;
    public string $rescheduleError  = '';

    // Check-in/out
    public string $checkinError = '';

    // Policy snapshot (primed in mount, refreshed after each action)
    public bool   $isLateCancellation = false;
    public ?float $hoursUntilSlot     = null;
    public array  $consequence         = [];
    public bool   $isCheckinOpen       = false;
    public bool   $isCheckinLate       = false;
    public ?string $checkinOpensAt     = null;  // formatted string for UI
    public ?string $checkinClosesAt    = null;  // formatted string for UI

    public function mount(string $uuid, PolicyService $policy): void
    {
        $this->reservation = Reservation::with([
            'service:id,uuid,slug,title,is_free,fee_amount,requires_manual_confirmation',
            'timeSlot:id,starts_at,ends_at,capacity,booked_count',
        ])
        ->where('uuid', $uuid)
        ->where('user_id', Auth::id())
        ->firstOrFail();

        $this->refreshPolicySnapshot($policy);
    }

    // ── Cancel ────────────────────────────────────────────────────────────────

    public function openCancelModal(): void
    {
        $this->cancelError     = '';
        $this->showCancelModal = true;
    }

    /**
     * Delegates to ReservationApiGateway — the same API contract consumed by
     * POST /api/v1/reservations/{uuid}/cancel.
     */
    public function confirmCancel(ReservationApiGateway $gateway, PolicyService $policy): void
    {
        $this->cancelError     = '';
        $this->showCancelModal = false;

        $result = $gateway->cancel(Auth::user(), $this->reservation->uuid);

        if (!$result->success) {
            $this->cancelError = $result->error;
            return;
        }

        $this->reservation = $result->reservation->load(['service', 'timeSlot']);
        $this->refreshPolicySnapshot($policy);
    }

    // ── Reschedule ────────────────────────────────────────────────────────────

    public function toggleReschedule(): void
    {
        $this->showReschedule  = !$this->showReschedule;
        $this->selectedSlotId  = null;
        $this->rescheduleError = '';
    }

    /**
     * Delegates to ReservationApiGateway — the same API contract consumed by
     * POST /api/v1/reservations/{uuid}/reschedule.
     */
    public function submitReschedule(ReservationApiGateway $gateway): void
    {
        $this->rescheduleError = '';

        if (!$this->selectedSlotId) {
            $this->rescheduleError = 'Please select a slot.';
            return;
        }

        $result = $gateway->reschedule(
            Auth::user(),
            $this->reservation->uuid,
            $this->selectedSlotId,
        );

        if (!$result->success) {
            $this->rescheduleError = $result->error;
            return;
        }

        $this->redirectRoute('reservations.show', ['uuid' => $result->reservation->uuid]);
    }

    // ── Check-in / Check-out ──────────────────────────────────────────────────

    /**
     * Delegates to ReservationApiGateway — the same API contract consumed by
     * POST /api/v1/reservations/{uuid}/check-in.
     */
    public function checkIn(ReservationApiGateway $gateway, PolicyService $policy): void
    {
        $this->checkinError = '';

        $result = $gateway->checkIn(Auth::user(), $this->reservation->uuid);

        if (!$result->success) {
            $this->checkinError = $result->error;
            return;
        }

        $this->reservation = $result->reservation->load(['service', 'timeSlot']);
        $this->refreshPolicySnapshot($policy);
    }

    /**
     * Delegates to ReservationApiGateway — the same API contract consumed by
     * POST /api/v1/reservations/{uuid}/check-out.
     */
    public function checkOut(ReservationApiGateway $gateway, PolicyService $policy): void
    {
        $this->checkinError = '';

        $result = $gateway->checkOut(Auth::user(), $this->reservation->uuid);

        if (!$result->success) {
            $this->checkinError = $result->error;
            return;
        }

        $this->reservation = $result->reservation->load(['service', 'timeSlot']);
        $this->refreshPolicySnapshot($policy);
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public function render(): \Illuminate\View\View
    {
        $availableSlots = collect();

        if ($this->showReschedule && in_array($this->reservation->status, ['pending', 'confirmed'])) {
            $availableSlots = TimeSlot::where('service_id', $this->reservation->service_id)
                ->where('id', '!=', $this->reservation->time_slot_id)
                ->where('status', 'available')
                ->where('starts_at', '>', now())
                ->orderBy('starts_at')
                ->limit(20)
                ->get();
        }

        return view('livewire.reservation.detail', compact('availableSlots'));
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function refreshPolicySnapshot(PolicyService $policy): void
    {
        $this->isLateCancellation = $policy->isLateCancellation($this->reservation);
        $this->hoursUntilSlot     = $policy->hoursUntilSlot($this->reservation);
        $this->consequence        = $policy->lateCancelConsequence();
        $this->isCheckinOpen      = $policy->isCheckinOpen($this->reservation);
        $this->isCheckinLate      = $policy->isCheckinLate($this->reservation);

        $opens  = $policy->checkinOpensAt($this->reservation);
        $closes = $policy->checkinClosesAt($this->reservation);
        $this->checkinOpensAt  = $opens?->format('g:i A');
        $this->checkinClosesAt = $closes?->format('g:i A');
    }
}
