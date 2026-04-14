<?php

namespace Tests\Feature\Reservation;

use App\Exceptions\InvalidStateTransitionException;
use App\Models\NoShowBreach;
use App\Models\PointsLedger;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\SystemConfig;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\Reservation\ReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tests for reservation cancellation:
 *  - happy-path status transition
 *  - slot count decrement for confirmed AND pending (bug-fix coverage)
 *  - late-cancel fee consequence
 *  - late-cancel points consequence
 *  - no consequence when within free window
 *  - guard against cancelling already-cancelled reservation
 */
class ReservationCancelTest extends TestCase
{
    use RefreshDatabase;

    private User    $user;
    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user    = User::factory()->create();
        $this->service = Service::factory()->create(['status' => 'active', 'requires_manual_confirmation' => false]);
        $this->actingAs($this->user);
    }

    // ── Happy-path transition ─────────────────────────────────────────────────

    public function test_cancel_confirmed_reservation_sets_status_cancelled(): void
    {
        $slot        = $this->slotFarFuture();
        $reservation = $this->makeConfirmedReservation($slot);

        app(ReservationService::class)->cancel($reservation, $this->user);

        $this->assertEquals('cancelled', $reservation->fresh()->status);
        $this->assertNotNull($reservation->fresh()->cancelled_at);
    }

    // ── Slot decrement ────────────────────────────────────────────────────────

    public function test_cancel_confirmed_reservation_decrements_slot_booked_count(): void
    {
        $slot = $this->slotFarFuture(booked: 1);

        $reservation = $this->makeConfirmedReservation($slot);

        app(ReservationService::class)->cancel($reservation, $this->user);

        $slot->refresh();
        $this->assertEquals(0, $slot->booked_count);
    }

    public function test_cancel_pending_reservation_also_decrements_slot_booked_count(): void
    {
        // Pending reservations also hold a slot — this was the existing bug
        $slot = $this->slotFarFuture(booked: 1);

        $reservation = $this->makePendingReservation($slot);

        app(ReservationService::class)->cancel($reservation, $this->user);

        $slot->refresh();
        $this->assertEquals(0, $slot->booked_count, 'Pending reservation cancellation must decrement slot count');
    }

    // ── Late-cancel: no consequence within free window ────────────────────────

    public function test_no_consequence_when_cancelling_within_free_window(): void
    {
        // Default lateCancelFreeHours = 24 hours; slot is 48h away → free window
        $slot        = $this->slotInFuture(hours: 48);
        $reservation = $this->makeConfirmedReservation($slot);

        app(ReservationService::class)->cancel($reservation, $this->user);

        $reservation->refresh();
        // Default column value is 'none' — no consequence applied
        $this->assertEquals('none', $reservation->cancellation_consequence);
        // Late cancellations do NOT create no-show breach records; only actual no-shows do
        $this->assertDatabaseMissing('no_show_breaches', ['reservation_id' => $reservation->id]);
    }

    // ── Late-cancel: fee consequence ──────────────────────────────────────────

    public function test_late_cancel_applies_fee_consequence(): void
    {
        // Default: type=fee, amount=25.00
        $this->setConfig('late_cancel_consequence_type', 'fee');
        $this->setConfig('late_cancel_fee_amount', '25.00');
        $this->setConfig('late_cancel_free_hours_before', '24');

        // Slot starts 12 hours from now — within 24h window
        $slot        = $this->slotInFuture(hours: 12);
        $reservation = $this->makeConfirmedReservation($slot);

        app(ReservationService::class)->cancel($reservation, $this->user);

        $reservation->refresh();
        $this->assertEquals('fee', $reservation->cancellation_consequence);
        $this->assertEquals('25.00', number_format($reservation->cancellation_consequence_amount, 2));

        // Late cancellations do NOT create no-show breach records; only actual no-shows do
        $this->assertDatabaseMissing('no_show_breaches', ['reservation_id' => $reservation->id]);
    }

    // ── Late-cancel: points consequence ──────────────────────────────────────

    public function test_late_cancel_debits_points_when_consequence_type_is_points(): void
    {
        $this->setConfig('late_cancel_consequence_type', 'points');
        $this->setConfig('late_cancel_points_amount', '50');
        $this->setConfig('late_cancel_free_hours_before', '24');

        $slot        = $this->slotInFuture(hours: 12);
        $reservation = $this->makeConfirmedReservation($slot);

        app(ReservationService::class)->cancel($reservation, $this->user);

        $reservation->refresh();
        $this->assertEquals('points', $reservation->cancellation_consequence);

        $this->assertDatabaseHas('points_ledger', [
            'user_id' => $this->user->id,
            'amount'  => -50,
        ]);

        $ledgerEntry = PointsLedger::where('user_id', $this->user->id)->latest('id')->first();
        $this->assertEquals(0, $ledgerEntry->balance_after, 'Balance cannot go below zero');
    }

    // ── Guard: cannot cancel a non-active reservation ─────────────────────────

    public function test_cancel_throws_for_already_cancelled_reservation(): void
    {
        $slot        = $this->slotFarFuture();
        $reservation = $this->makeReservation($slot, 'cancelled');

        $this->expectException(InvalidStateTransitionException::class);

        app(ReservationService::class)->cancel($reservation, $this->user);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function slotFarFuture(int $booked = 1): TimeSlot
    {
        return TimeSlot::factory()->create([
            'service_id'   => $this->service->id,
            'starts_at'    => now()->addDays(7),
            'ends_at'      => now()->addDays(7)->addHour(),
            'capacity'     => 10,
            'booked_count' => $booked,
            'status'       => 'available',
        ]);
    }

    private function slotInFuture(int $hours): TimeSlot
    {
        return TimeSlot::factory()->create([
            'service_id'   => $this->service->id,
            'starts_at'    => now()->addHours($hours),
            'ends_at'      => now()->addHours($hours)->addHour(),
            'capacity'     => 10,
            'booked_count' => 1,
            'status'       => 'available',
        ]);
    }

    private function makeConfirmedReservation(TimeSlot $slot): Reservation
    {
        return $this->makeReservation($slot, 'confirmed');
    }

    private function makePendingReservation(TimeSlot $slot): Reservation
    {
        return $this->makeReservation($slot, 'pending');
    }

    private function makeReservation(TimeSlot $slot, string $status): Reservation
    {
        return Reservation::create([
            'uuid'         => (string) Str::uuid(),
            'user_id'      => $this->user->id,
            'service_id'   => $this->service->id,
            'time_slot_id' => $slot->id,
            'status'       => $status,
            'requested_at' => now()->subMinutes(5),
        ]);
    }

    private function setConfig(string $key, string $value): void
    {
        Cache::forget('sysconfig:' . $key);
        SystemConfig::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
