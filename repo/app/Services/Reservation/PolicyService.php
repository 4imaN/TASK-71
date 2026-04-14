<?php

namespace App\Services\Reservation;

use App\Models\BookingFreeze;
use App\Models\NoShowBreach;
use App\Models\PointsLedger;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Admin\SystemConfigService;
use Illuminate\Support\Carbon;

/**
 * Encapsulates late-cancel, check-in window, no-show, and breach/freeze
 * policy rules.
 *
 * Called by ReservationService during cancel(), checkIn(), markNoShow().
 * Exposed to Livewire components so the UI can warn the learner before
 * they confirm an action that carries a consequence.
 *
 * Breach semantics:
 *  - Only ACTUAL NO-SHOWS create NoShowBreach records (breach_type='no_show').
 *  - Late cancellations apply a fee/points consequence but do NOT create
 *    a breach record and do NOT count toward the freeze threshold.
 */
class PolicyService
{
    public function __construct(private readonly SystemConfigService $config) {}

    // ── Late-cancel ───────────────────────────────────────────────────────────

    /**
     * Whether this cancellation counts as "late".
     * Late = slot is in the future AND less than lateCancelFreeHours() away.
     */
    public function isLateCancellation(Reservation $reservation): bool
    {
        $slot = $reservation->timeSlot;
        if (!$slot || $slot->starts_at->isPast()) {
            return false;
        }

        $hoursUntil = now()->diffInRealHours($slot->starts_at);
        return $hoursUntil < $this->config->lateCancelFreeHours();
    }

    /**
     * Return hours until slot start, or null if the slot has passed.
     * Used by the UI to show "you have X hours left in the free window".
     */
    public function hoursUntilSlot(Reservation $reservation): ?float
    {
        $slot = $reservation->timeSlot;
        if (!$slot || $slot->starts_at->isPast()) {
            return null;
        }
        return now()->diffInRealHours($slot->starts_at);
    }

    /**
     * The configured consequence type and amount for the current policy.
     *
     * @return array{type: string, amount: float}
     */
    public function lateCancelConsequence(): array
    {
        $type = $this->config->lateCancelConsequenceType(); // 'fee' | 'points'
        $amount = $type === 'points'
            ? (float) $this->config->lateCancelPointsAmount()
            : $this->config->lateCancelFeeAmount();

        return ['type' => $type, 'amount' => $amount];
    }

    /**
     * Apply the consequence columns to the reservation and (for points) write
     * the ledger entry.
     *
     * Late cancellations do NOT create a NoShowBreach record — only actual
     * no-shows do. The fee/points consequence is separate from the breach policy.
     *
     * Must be called inside the same DB transaction as the cancel/reschedule update.
     *
     * @return array{type: string, amount: float}
     */
    public function applyLateCancelConsequence(Reservation $reservation, User $actor): array
    {
        ['type' => $type, 'amount' => $amount] = $this->lateCancelConsequence();

        $reservation->update([
            'cancellation_consequence'        => $type,
            'cancellation_consequence_amount' => $amount,
        ]);

        if ($type === 'points') {
            $this->debitPoints(
                user: $actor,
                amount: (int) $amount,
                reason: 'Late cancellation — ' . ($reservation->service?->title ?? 'service'),
                refType: 'reservation',
                refId: $reservation->id,
            );
        }
        // 'fee' consequences are recorded on the reservation row; external billing
        // collection is out of scope for this slice.

        return ['type' => $type, 'amount' => $amount];
    }

    /**
     * Append a negative entry to the user's points ledger.
     * Balance cannot go below zero.
     */
    public function debitPoints(User $user, int $amount, string $reason, string $refType, int $refId): void
    {
        $current = $user->pointsBalance();
        PointsLedger::create([
            'user_id'        => $user->id,
            'amount'         => -abs($amount),
            'reason'         => $reason,
            'reference_type' => $refType,
            'reference_id'   => $refId,
            'balance_after'  => max(0, $current - abs($amount)),
        ]);
    }

    // ── Check-in window ───────────────────────────────────────────────────────

    /**
     * The timestamp at which the check-in window opens for this reservation.
     * Returns null if the slot is not loaded.
     */
    public function checkinOpensAt(Reservation $reservation): ?Carbon
    {
        $slot = $reservation->timeSlot;
        if (!$slot) {
            return null;
        }
        return $slot->starts_at->copy()->subMinutes($this->config->checkinOpensMinsBeforeStart());
    }

    /**
     * The timestamp at which the check-in window closes for this reservation.
     * Returns null if the slot is not loaded.
     */
    public function checkinClosesAt(Reservation $reservation): ?Carbon
    {
        $slot = $reservation->timeSlot;
        if (!$slot) {
            return null;
        }
        return $slot->starts_at->copy()->addMinutes($this->config->checkinClosedMinsAfterStart());
    }

    /**
     * Whether the check-in window is currently open.
     * Window = [starts_at - opensMins, starts_at + closesMins].
     */
    public function isCheckinOpen(Reservation $reservation): bool
    {
        $opens  = $this->checkinOpensAt($reservation);
        $closes = $this->checkinClosesAt($reservation);
        if (!$opens || !$closes) {
            return false;
        }
        return now()->between($opens, $closes);
    }

    /**
     * Whether a check-in right now would be a late arrival.
     * True when the window is open AND the slot start time has already passed.
     */
    public function isCheckinLate(Reservation $reservation): bool
    {
        $slot = $reservation->timeSlot;
        if (!$slot) {
            return false;
        }
        return $this->isCheckinOpen($reservation) && now()->gt($slot->starts_at);
    }

    // ── No-show ───────────────────────────────────────────────────────────────

    /**
     * Whether this reservation qualifies as a no-show.
     *
     * A no-show is a reservation that:
     *  - is still in 'confirmed' status (never checked in), AND
     *  - the check-in window has already closed.
     */
    public function isNoShow(Reservation $reservation): bool
    {
        if ($reservation->status !== 'confirmed') {
            return false;
        }
        $closes = $this->checkinClosesAt($reservation);
        if (!$closes) {
            return false;
        }
        return now()->gt($closes);
    }

    /**
     * Count of no-show breaches for the user in the rolling window.
     * Only counts breach_type='no_show' — late cancellations are excluded.
     */
    public function activeNoShowBreachCount(User $user): int
    {
        return NoShowBreach::where('user_id', $user->id)
            ->where('breach_type', 'no_show')
            ->where('occurred_at', '>=', now()->subDays($this->config->noshowBreachWindowDays()))
            ->count();
    }

    /**
     * Record a no-show breach and apply a booking freeze if the rolling-window
     * threshold is crossed.
     *
     * Returns an outcome array so the caller (ReservationService) can emit
     * discrete audit log entries for each policy event:
     *
     *   [
     *     'breach_count'   => int,   // total no_show breaches in the rolling window
     *     'freeze_applied' => bool,  // whether a freeze was created/extended
     *     'freeze_until'   => Carbon|null,
     *   ]
     *
     * Must be called inside the same DB transaction as the no_show status update.
     *
     * @return array{breach_count: int, freeze_applied: bool, freeze_until: \Illuminate\Support\Carbon|null}
     */
    public function applyNoShowConsequence(Reservation $reservation): array
    {
        $user = $reservation->user;

        NoShowBreach::create([
            'user_id'        => $user->id,
            'reservation_id' => $reservation->id,
            'breach_type'    => 'no_show',
            'occurred_at'    => now(),
        ]);

        // Check rolling-window count (includes the breach just created above)
        $count = $this->activeNoShowBreachCount($user);

        $freezeApplied = false;
        $freezeUntil   = null;

        if ($count >= $this->config->noshowBreachThreshold()) {
            $freezeEnds = now()->addDays($this->config->noshowFreezeDays());

            // Only advance the freeze if it would extend beyond any current freeze
            if (!$user->booking_freeze_until || $freezeEnds->gt($user->booking_freeze_until)) {
                $user->update(['booking_freeze_until' => $freezeEnds]);
                $freezeApplied = true;
                $freezeUntil   = $freezeEnds;
            }

            BookingFreeze::create([
                'user_id'              => $user->id,
                'starts_at'            => now(),
                'ends_at'              => $freezeEnds,
                'reason'               => "Automatic freeze: {$count} no-show breach(es) in "
                                          . $this->config->noshowBreachWindowDays() . ' days.',
                'trigger_breach_count' => $count,
            ]);
        }

        return [
            'breach_count'   => $count,
            'freeze_applied' => $freezeApplied,
            'freeze_until'   => $freezeUntil,
        ];
    }
}
