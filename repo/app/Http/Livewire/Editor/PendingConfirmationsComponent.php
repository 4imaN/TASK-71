<?php

namespace App\Http\Livewire\Editor;

use App\Exceptions\InvalidStateTransitionException;
use App\Models\Reservation;
use App\Services\Reservation\ReservationService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Operator surface for reviewing and confirming (or rejecting) reservations
 * that were created for services with requires_manual_confirmation = true.
 *
 * Only pending reservations that belong to manual-confirm services are shown.
 * Confirm transitions the reservation to 'confirmed' via ReservationService.
 * Reject cancels the reservation so the slot capacity is freed.
 *
 * Route: GET /editor/pending  (role:content_editor|administrator)
 */
#[Layout('layouts.app')]
class PendingConfirmationsComponent extends Component
{
    use WithPagination;

    // ── Reject modal ──────────────────────────────────────────────────────────
    public bool $showRejectModal       = false;
    public int  $rejectReservationId   = 0;
    public string $rejectReservationLabel = '';

    // ── Flash ─────────────────────────────────────────────────────────────────
    public string $flashMessage = '';
    public string $flashType    = 'success';

    // ── Confirm ───────────────────────────────────────────────────────────────

    /**
     * Confirm a pending manual-review reservation.
     * Transitions status pending → confirmed and decrements no extra capacity
     * (the slot was already counted when the reservation was created).
     */
    public function confirm(int $reservationId, ReservationService $reservationService): void
    {
        $reservation = Reservation::find($reservationId);

        if (!$reservation || $reservation->status !== 'pending') {
            $this->flash('Reservation is no longer pending.', 'error');
            return;
        }

        try {
            $reservationService->confirm(
                reservation: $reservation,
                actorId:     Auth::id(),
                actorType:   'user',
            );
            $this->flash('Reservation confirmed successfully.', 'success');
        } catch (InvalidStateTransitionException $e) {
            $this->flash($e->getMessage(), 'error');
        }

        $this->resetPage();
    }

    // ── Reject modal ──────────────────────────────────────────────────────────

    public function openRejectModal(int $reservationId, string $label): void
    {
        $this->rejectReservationId    = $reservationId;
        $this->rejectReservationLabel = $label;
        $this->showRejectModal        = true;
    }

    public function closeRejectModal(): void
    {
        $this->showRejectModal        = false;
        $this->rejectReservationId    = 0;
        $this->rejectReservationLabel = '';
    }

    /**
     * Reject (cancel) the pending reservation after operator confirms the modal.
     * Frees the slot capacity so other learners can book it.
     */
    public function confirmReject(ReservationService $reservationService): void
    {
        $this->showRejectModal = false;

        $reservation = Reservation::find($this->rejectReservationId);

        if (!$reservation) {
            $this->flash('Reservation not found.', 'error');
            $this->rejectReservationId = 0;
            return;
        }

        if (!in_array($reservation->status, ['pending', 'confirmed'])) {
            $this->flash('This reservation cannot be cancelled in its current state.', 'error');
            $this->rejectReservationId = 0;
            return;
        }

        try {
            $reservationService->cancel(
                reservation: $reservation,
                actor:       Auth::user(),
            );
            $this->flash('Reservation rejected and cancelled.', 'success');
        } catch (InvalidStateTransitionException $e) {
            $this->flash($e->getMessage(), 'error');
        }

        $this->rejectReservationId = 0;
        $this->resetPage();
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public function render(): \Illuminate\View\View
    {
        $pending = Reservation::with([
                'user:id,username,display_name,audience_type',
                'service:id,title,slug',
                'timeSlot:id,starts_at,ends_at,capacity,booked_count',
            ])
            ->whereHas('service', fn ($q) => $q->where('requires_manual_confirmation', true))
            ->where('status', 'pending')
            ->orderBy('created_at', 'asc')
            ->paginate(20);

        return view('livewire.editor.pending-confirmations', compact('pending'));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function flash(string $message, string $type): void
    {
        $this->flashMessage = $message;
        $this->flashType    = $type;
    }
}
