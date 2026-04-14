<?php

namespace App\Services\Reservation;

use App\Exceptions\BookingFrozenException;
use App\Exceptions\EligibilityViolationException;
use App\Exceptions\InvalidStateTransitionException;
use App\Exceptions\SlotUnavailableException;
use App\Models\Reservation;
use App\Models\ReservationStatusHistory;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\Admin\SystemConfigService;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Core reservation lifecycle service.
 *
 * All state transitions go through this service. No business logic
 * lives in controllers or Livewire components — they delegate here.
 */
class ReservationService
{
    public function __construct(
        private readonly SystemConfigService  $config,
        private readonly AuditLogger          $auditLogger,
        private readonly SlotAvailabilityService $slotAvailability,
        private readonly PolicyService        $policy,
    ) {}

    /**
     * Create a new PENDING (or auto-CONFIRMED) reservation.
     * Guards: booking freeze, eligibility, slot capacity.
     */
    public function create(User $user, TimeSlot $slot): Reservation
    {
        // Guard: booking freeze
        if ($user->isBookingFrozen()) {
            throw new BookingFrozenException(
                'Your account has an active booking freeze until ' .
                $user->booking_freeze_until->format('M j, Y g:i A') . '.'
            );
        }

        // Guard: audience eligibility
        $service = $slot->service;
        if ($service->audiences()->count() > 0) {
            $eligible = $service->audiences()
                ->where('target_audiences.code', $user->audience_type)
                ->exists();
            if (!$eligible) {
                throw new EligibilityViolationException(
                    'You are not eligible to book this service.'
                );
            }
        }

        // Guard: slot capacity (pessimistic — re-checked inside transaction)
        if (!$this->slotAvailability->hasCapacity($slot)) {
            throw new SlotUnavailableException(
                'This time slot is no longer available.'
            );
        }

        return DB::transaction(function () use ($user, $slot, $service) {
            // Double-check capacity inside the transaction lock
            if (!$this->slotAvailability->hasCapacity($slot)) {
                throw new SlotUnavailableException('This time slot is no longer available.');
            }

            $expiresAt = now()->addMinutes($this->config->pendingExpiryMinutes());

            $reservation = Reservation::create([
                'uuid'         => (string) Str::uuid(),
                'user_id'      => $user->id,
                'service_id'   => $service->id,
                'time_slot_id' => $slot->id,
                'status'       => 'pending',
                'requested_at' => now(),
                'expires_at'   => $expiresAt,
            ]);

            $this->recordStatusHistory($reservation, null, 'pending', $user->id, 'user');

            // Auto-confirm if service does not require manual confirmation
            if (!$service->requires_manual_confirmation) {
                $this->confirm($reservation, actorId: null, actorType: 'system');
            } else {
                // Count the slot for manual-confirm path so capacity is reserved
                $this->slotAvailability->incrementBookedCount($slot);
            }

            $this->auditLogger->log(
                action: 'reservation.created',
                actorId: $user->id,
                entityType: 'reservation',
                entityId: $reservation->id,
                afterState: ['status' => $reservation->fresh()->status, 'slot_id' => $slot->id],
            );

            return $reservation->refresh();
        });
    }

    /**
     * Confirm a PENDING reservation (admin / system action).
     */
    public function confirm(Reservation $reservation, ?int $actorId, string $actorType = 'user'): Reservation
    {
        if ($reservation->status !== 'pending') {
            throw new InvalidStateTransitionException(
                "Cannot confirm a reservation with status '{$reservation->status}'."
            );
        }

        DB::transaction(function () use ($reservation, $actorId, $actorType) {
            $reservation->update([
                'status'       => 'confirmed',
                'confirmed_at' => now(),
                'expires_at'   => null,
            ]);

            // Capacity accounting:
            //
            // Auto-confirm path (called from create() when requires_manual_confirmation=false):
            //   create() did NOT call incrementBookedCount before delegating here, so we must
            //   increment now → one increment total ✓
            //
            // Manual operator-confirm path (called by PendingConfirmationsComponent after the
            //   reservation was created as 'pending'):
            //   create() already called incrementBookedCount to hold the slot for the pending
            //   reservation, so we must NOT increment again → still one increment total ✓
            $service = $reservation->service ?? $reservation->loadMissing('service')->service;

            if (!$service?->requires_manual_confirmation) {
                $this->slotAvailability->incrementBookedCount($reservation->timeSlot);
            }

            $this->recordStatusHistory($reservation, 'pending', 'confirmed', $actorId, $actorType);

            $this->auditLogger->log(
                action: 'reservation.confirmed',
                actorId: $actorId,
                actorType: $actorType,
                entityType: 'reservation',
                entityId: $reservation->id,
            );
        });

        return $reservation->refresh();
    }

    /**
     * Cancel a reservation.
     *
     * - Allows cancellation of pending or confirmed reservations.
     * - Decrements slot booked_count for BOTH pending and confirmed (both consume capacity).
     * - Applies late-cancel consequence when the slot is within the policy window.
     */
    public function cancel(Reservation $reservation, User $actor, ?int $reasonId = null): Reservation
    {
        if (!in_array($reservation->status, ['pending', 'confirmed'])) {
            throw new InvalidStateTransitionException(
                "Cannot cancel a reservation with status '{$reservation->status}'."
            );
        }

        return DB::transaction(function () use ($reservation, $actor, $reasonId) {
            $previousStatus = $reservation->status;

            // Apply late-cancel consequence before updating status (needs pre-cancel state)
            $consequence = null;
            if ($this->policy->isLateCancellation($reservation)) {
                $consequence = $this->policy->applyLateCancelConsequence($reservation, $actor);
            }

            $reservation->update([
                'status'                 => 'cancelled',
                'cancelled_at'           => now(),
                'cancellation_reason_id' => $reasonId,
            ]);

            // Both pending and confirmed reservations hold a booked slot
            $this->slotAvailability->decrementBookedCount($reservation->timeSlot);

            $this->recordStatusHistory($reservation, $previousStatus, 'cancelled', $actor->id, 'user');

            $this->auditLogger->log(
                action: 'reservation.cancelled',
                actorId: $actor->id,
                entityType: 'reservation',
                entityId: $reservation->id,
                beforeState: ['status' => $previousStatus],
                afterState: [
                    'status'      => 'cancelled',
                    'reason_id'   => $reasonId,
                    'consequence' => $consequence,
                ],
            );

            return $reservation->refresh();
        });
    }

    /**
     * Reschedule a reservation to a different future slot.
     *
     * Modelled as a cancellation of the original reservation plus creation of a
     * replacement reservation. If the original slot is inside the late-cancel
     * policy window the same fee/points consequence applies to the original
     * reservation — the learner chose to move within the window.
     *
     * Marks the existing reservation as 'rescheduled' and creates a new
     * PENDING (or auto-CONFIRMED) reservation linked via rescheduled_from_id.
     */
    public function reschedule(Reservation $reservation, TimeSlot $newSlot, User $actor): Reservation
    {
        if (!in_array($reservation->status, ['pending', 'confirmed'])) {
            throw new InvalidStateTransitionException(
                "Cannot reschedule a reservation with status '{$reservation->status}'."
            );
        }

        if ($newSlot->id === $reservation->time_slot_id) {
            throw new InvalidStateTransitionException('Cannot reschedule to the same slot.');
        }

        if (!$this->slotAvailability->hasCapacity($newSlot)) {
            throw new SlotUnavailableException('The selected slot is no longer available.');
        }

        return DB::transaction(function () use ($reservation, $newSlot, $actor) {
            // Re-check capacity inside the lock
            if (!$this->slotAvailability->hasCapacity($newSlot)) {
                throw new SlotUnavailableException('The selected slot is no longer available.');
            }

            $oldSlot        = $reservation->timeSlot;
            $previousStatus = $reservation->status;

            // Apply late-cancel consequence to the original reservation if the
            // move happens inside the policy window (same rule as a plain cancel).
            $consequence = null;
            if ($this->policy->isLateCancellation($reservation)) {
                $consequence = $this->policy->applyLateCancelConsequence($reservation, $actor);
            }

            // Mark old reservation as rescheduled and free its slot
            $reservation->update([
                'status'       => 'rescheduled',
                'cancelled_at' => now(),
            ]);
            $this->slotAvailability->decrementBookedCount($oldSlot);
            $this->recordStatusHistory($reservation, $previousStatus, 'rescheduled', $actor->id, 'user');

            // Create the replacement reservation
            $service   = $newSlot->service;
            $expiresAt = now()->addMinutes($this->config->pendingExpiryMinutes());

            $newReservation = Reservation::create([
                'uuid'                => (string) Str::uuid(),
                'user_id'             => $actor->id,
                'service_id'          => $service->id,
                'time_slot_id'        => $newSlot->id,
                'status'              => 'pending',
                'requested_at'        => now(),
                'expires_at'          => $expiresAt,
                'rescheduled_from_id' => $reservation->id,
            ]);

            $this->recordStatusHistory($newReservation, null, 'pending', $actor->id, 'user');

            if (!$service->requires_manual_confirmation) {
                $this->confirm($newReservation, actorId: null, actorType: 'system');
            } else {
                $this->slotAvailability->incrementBookedCount($newSlot);
            }

            $this->auditLogger->log(
                action: 'reservation.rescheduled',
                actorId: $actor->id,
                entityType: 'reservation',
                entityId: $reservation->id,
                beforeState: ['slot_id' => $oldSlot->id, 'status' => $previousStatus],
                afterState:  [
                    'new_reservation_id' => $newReservation->id,
                    'new_slot_id'        => $newSlot->id,
                    'consequence'        => $consequence,
                ],
            );

            return $newReservation->refresh();
        });
    }

    /**
     * Expire a PENDING reservation whose expiry time has passed.
     * Called by the ExpirePendingReservations artisan command.
     */
    public function expire(Reservation $reservation): Reservation
    {
        if ($reservation->status !== 'pending') {
            throw new InvalidStateTransitionException(
                "Cannot expire a reservation with status '{$reservation->status}'."
            );
        }

        DB::transaction(function () use ($reservation) {
            $reservation->update(['status' => 'expired']);

            $this->slotAvailability->decrementBookedCount($reservation->timeSlot);

            $this->recordStatusHistory($reservation, 'pending', 'expired', null, 'system');

            $this->auditLogger->log(
                action: 'reservation.expired',
                actorId: null,
                actorType: 'system',
                entityType: 'reservation',
                entityId: $reservation->id,
            );
        });

        return $reservation->refresh();
    }

    // ── Attendance ────────────────────────────────────────────────────────────

    /**
     * Check in a learner for a confirmed reservation.
     *
     * Timing rules (from SystemConfig):
     *  - Window opens: starts_at - checkinOpensMinsBeforeStart (default 15 min)
     *  - Window closes: starts_at + checkinClosedMinsAfterStart (default 10 min)
     *
     * If the slot start has already passed when the learner checks in, the
     * status transitions to 'partial_attendance' (late arrival). Otherwise
     * it transitions to 'checked_in'.
     */
    public function checkIn(Reservation $reservation, User $actor): Reservation
    {
        if ($reservation->status !== 'confirmed') {
            throw new InvalidStateTransitionException(
                "Cannot check in a reservation with status '{$reservation->status}'."
            );
        }

        if (!$this->policy->isCheckinOpen($reservation)) {
            $opens = $this->policy->checkinOpensAt($reservation);
            if ($opens && now()->lt($opens)) {
                throw new InvalidStateTransitionException(
                    'Check-in is not open yet. Window opens at ' . $opens->format('g:i A') . '.'
                );
            }
            throw new InvalidStateTransitionException(
                'The check-in window for this reservation has closed.'
            );
        }

        $isLate    = $this->policy->isCheckinLate($reservation);
        $newStatus = $isLate ? 'partial_attendance' : 'checked_in';

        DB::transaction(function () use ($reservation, $actor, $newStatus, $isLate) {
            $previousStatus = $reservation->status;

            $reservation->update([
                'status'        => $newStatus,
                'checked_in_at' => now(),
            ]);

            $this->recordStatusHistory($reservation, $previousStatus, $newStatus, $actor->id, 'user');

            $this->auditLogger->log(
                action: 'reservation.checked_in',
                actorId: $actor->id,
                entityType: 'reservation',
                entityId: $reservation->id,
                afterState: ['status' => $newStatus, 'late_arrival' => $isLate],
            );
        });

        return $reservation->refresh();
    }

    /**
     * Check out from a reservation that was checked in (on-time or partial).
     */
    public function checkOut(Reservation $reservation, User $actor): Reservation
    {
        if (!in_array($reservation->status, ['checked_in', 'partial_attendance'])) {
            throw new InvalidStateTransitionException(
                "Cannot check out a reservation with status '{$reservation->status}'."
            );
        }

        DB::transaction(function () use ($reservation, $actor) {
            $previousStatus = $reservation->status;

            $reservation->update([
                'status'         => 'checked_out',
                'checked_out_at' => now(),
            ]);

            $this->recordStatusHistory($reservation, $previousStatus, 'checked_out', $actor->id, 'user');

            $this->auditLogger->log(
                action: 'reservation.checked_out',
                actorId: $actor->id,
                entityType: 'reservation',
                entityId: $reservation->id,
            );
        });

        return $reservation->refresh();
    }

    /**
     * Mark a confirmed reservation as a no-show after the check-in window closes.
     *
     * Records a no-show breach for the user and applies a booking freeze
     * if the rolling-window threshold is reached.
     *
     * Called by the MarkNoShowReservations artisan command.
     */
    public function markNoShow(Reservation $reservation): Reservation
    {
        if ($reservation->status !== 'confirmed') {
            throw new InvalidStateTransitionException(
                "Cannot mark no-show for a reservation with status '{$reservation->status}'."
            );
        }

        if (!$this->policy->isNoShow($reservation)) {
            throw new InvalidStateTransitionException(
                'The check-in window has not yet closed for this reservation.'
            );
        }

        DB::transaction(function () use ($reservation) {
            $reservation->update(['status' => 'no_show']);

            $this->recordStatusHistory($reservation, 'confirmed', 'no_show', null, 'system');

            // Ensure the user relationship is loaded before passing to policy
            $reservation->loadMissing('user');

            // applyNoShowConsequence() returns the policy outcome so we can
            // write discrete, immutable audit entries for each enforcement event.
            $outcome = $this->policy->applyNoShowConsequence($reservation);

            // 1. Status transition
            $this->auditLogger->log(
                action: 'reservation.no_show',
                actorId: null,
                actorType: 'system',
                entityType: 'reservation',
                entityId: $reservation->id,
                afterState: ['slot_id' => $reservation->time_slot_id],
            );

            // 2. Breach recorded (policy event — entity is the user whose breach count changed)
            $this->auditLogger->log(
                action: 'policy.noshow_breach_recorded',
                actorId: null,
                actorType: 'system',
                entityType: 'user',
                entityId: $reservation->user->id,
                afterState: [
                    'reservation_id' => $reservation->id,
                    'breach_count'   => $outcome['breach_count'],
                    'window_days'    => $this->config->noshowBreachWindowDays(),
                    'threshold'      => $this->config->noshowBreachThreshold(),
                ],
            );

            // 3. Freeze applied (only when threshold crossed and freeze was advanced)
            if ($outcome['freeze_applied']) {
                $this->auditLogger->log(
                    action: 'policy.booking_freeze_applied',
                    actorId: null,
                    actorType: 'system',
                    entityType: 'user',
                    entityId: $reservation->user->id,
                    afterState: [
                        'freeze_until'    => $outcome['freeze_until']->toIso8601String(),
                        'trigger_count'   => $outcome['breach_count'],
                        'reservation_id'  => $reservation->id,
                    ],
                );
            }
        });

        return $reservation->refresh();
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function recordStatusHistory(
        Reservation $reservation,
        ?string $from,
        string $to,
        ?int $actorId,
        string $actorType
    ): void {
        ReservationStatusHistory::create([
            'reservation_id' => $reservation->id,
            'from_status'    => $from,
            'to_status'      => $to,
            'actor_id'       => $actorId,
            'actor_type'     => $actorType,
            'occurred_at'    => now(),
        ]);
    }
}
