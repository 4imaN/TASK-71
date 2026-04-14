<?php

namespace Tests\Feature\Reservation;

use App\Exceptions\InvalidStateTransitionException;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\Reservation\ReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tests for check-in and check-out lifecycle:
 *  - on-time check-in → 'checked_in'
 *  - late-arrival check-in → 'partial_attendance'
 *  - window not yet open → exception
 *  - window already closed → exception
 *  - wrong status → exception
 *  - check-out from checked_in / partial_attendance → 'checked_out'
 *  - check-out from wrong status → exception
 *  - timestamps recorded
 */
class ReservationCheckinTest extends TestCase
{
    use RefreshDatabase;

    private User    $user;
    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user    = User::factory()->create();
        $this->service = Service::factory()->create([
            'status'                       => 'active',
            'requires_manual_confirmation' => false,
        ]);
        $this->actingAs($this->user);
    }

    // ── On-time check-in ──────────────────────────────────────────────────────

    public function test_checkin_before_start_produces_checked_in_status(): void
    {
        // Window opens 15 min before start; slot starts 10 minutes from now
        // → we are inside the window, before the start → on-time
        $slot        = $this->slotStartingIn(minutes: 10);
        $reservation = $this->makeConfirmedReservation($slot);

        $reservation = app(ReservationService::class)->checkIn($reservation, $this->user);

        $this->assertEquals('checked_in', $reservation->status);
        $this->assertNotNull($reservation->checked_in_at);
    }

    public function test_checkin_records_status_history(): void
    {
        $slot        = $this->slotStartingIn(minutes: 10);
        $reservation = $this->makeConfirmedReservation($slot);

        app(ReservationService::class)->checkIn($reservation, $this->user);

        $this->assertDatabaseHas('reservation_status_history', [
            'reservation_id' => $reservation->id,
            'from_status'    => 'confirmed',
            'to_status'      => 'checked_in',
            'actor_type'     => 'user',
        ]);
    }

    // ── Late arrival ──────────────────────────────────────────────────────────

    public function test_checkin_after_start_but_within_window_produces_partial_attendance(): void
    {
        // Slot started 5 minutes ago; window closes 10 minutes after start
        // → 5 min into the 10-min post-start window → late arrival
        $slot        = $this->slotStartedMinutesAgo(minutes: 5);
        $reservation = $this->makeConfirmedReservation($slot);

        $reservation = app(ReservationService::class)->checkIn($reservation, $this->user);

        $this->assertEquals('partial_attendance', $reservation->status);
        $this->assertNotNull($reservation->checked_in_at);
    }

    // ── Window not yet open ───────────────────────────────────────────────────

    public function test_checkin_throws_when_window_not_yet_open(): void
    {
        // Slot starts in 30 minutes; window opens 15 min before → not open yet
        $slot        = $this->slotStartingIn(minutes: 30);
        $reservation = $this->makeConfirmedReservation($slot);

        $this->expectException(InvalidStateTransitionException::class);
        $this->expectExceptionMessageMatches('/not open yet/i');

        app(ReservationService::class)->checkIn($reservation, $this->user);
    }

    // ── Window already closed ─────────────────────────────────────────────────

    public function test_checkin_throws_when_window_has_closed(): void
    {
        // Slot started 15 minutes ago; window closes at +10 min → closed
        $slot        = $this->slotStartedMinutesAgo(minutes: 15);
        $reservation = $this->makeConfirmedReservation($slot);

        $this->expectException(InvalidStateTransitionException::class);
        $this->expectExceptionMessageMatches('/closed/i');

        app(ReservationService::class)->checkIn($reservation, $this->user);
    }

    // ── Wrong status ──────────────────────────────────────────────────────────

    public function test_checkin_throws_for_already_checked_in_reservation(): void
    {
        $slot        = $this->slotStartingIn(minutes: 10);
        $reservation = $this->makeReservation($slot, 'checked_in');

        $this->expectException(InvalidStateTransitionException::class);

        app(ReservationService::class)->checkIn($reservation, $this->user);
    }

    public function test_checkin_throws_for_cancelled_reservation(): void
    {
        $slot        = $this->slotStartingIn(minutes: 10);
        $reservation = $this->makeReservation($slot, 'cancelled');

        $this->expectException(InvalidStateTransitionException::class);

        app(ReservationService::class)->checkIn($reservation, $this->user);
    }

    // ── Check-out ─────────────────────────────────────────────────────────────

    public function test_checkout_from_checked_in_produces_checked_out_status(): void
    {
        $slot        = $this->slotStartingIn(minutes: 10);
        $reservation = $this->makeReservation($slot, 'checked_in');

        $reservation = app(ReservationService::class)->checkOut($reservation, $this->user);

        $this->assertEquals('checked_out', $reservation->status);
        $this->assertNotNull($reservation->checked_out_at);
    }

    public function test_checkout_from_partial_attendance_produces_checked_out_status(): void
    {
        $slot        = $this->slotStartingIn(minutes: 10);
        $reservation = $this->makeReservation($slot, 'partial_attendance');

        $reservation = app(ReservationService::class)->checkOut($reservation, $this->user);

        $this->assertEquals('checked_out', $reservation->status);
    }

    public function test_checkout_throws_for_confirmed_status(): void
    {
        $slot        = $this->slotStartingIn(minutes: 10);
        $reservation = $this->makeConfirmedReservation($slot);

        $this->expectException(InvalidStateTransitionException::class);

        app(ReservationService::class)->checkOut($reservation, $this->user);
    }

    public function test_checkout_throws_for_cancelled_status(): void
    {
        $slot        = $this->slotStartingIn(minutes: 10);
        $reservation = $this->makeReservation($slot, 'cancelled');

        $this->expectException(InvalidStateTransitionException::class);

        app(ReservationService::class)->checkOut($reservation, $this->user);
    }

    // ── REST API ──────────────────────────────────────────────────────────────

    public function test_api_checkin_returns_updated_reservation(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\ValidateAppSession::class);

        $slot        = $this->slotStartingIn(minutes: 10);
        $reservation = $this->makeConfirmedReservation($slot);

        $response = $this->postJson("/api/v1/reservations/{$reservation->uuid}/check-in");

        $response->assertOk()
                 ->assertJsonPath('reservation.status', 'checked_in');
    }

    public function test_api_checkin_returns_422_when_window_closed(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\ValidateAppSession::class);

        $slot        = $this->slotStartedMinutesAgo(minutes: 15);
        $reservation = $this->makeConfirmedReservation($slot);

        $this->postJson("/api/v1/reservations/{$reservation->uuid}/check-in")
             ->assertStatus(422);
    }

    public function test_api_checkout_returns_updated_reservation(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\ValidateAppSession::class);

        $slot        = $this->slotStartingIn(minutes: 10);
        $reservation = $this->makeReservation($slot, 'checked_in');

        $response = $this->postJson("/api/v1/reservations/{$reservation->uuid}/check-out");

        $response->assertOk()
                 ->assertJsonPath('reservation.status', 'checked_out');
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /** Slot that starts N minutes from now (inside check-in window if N < 15). */
    private function slotStartingIn(int $minutes): TimeSlot
    {
        return TimeSlot::factory()->create([
            'service_id'   => $this->service->id,
            'starts_at'    => now()->addMinutes($minutes),
            'ends_at'      => now()->addMinutes($minutes + 60),
            'capacity'     => 10,
            'booked_count' => 1,
            'status'       => 'available',
        ]);
    }

    /** Slot that started N minutes ago. */
    private function slotStartedMinutesAgo(int $minutes): TimeSlot
    {
        return TimeSlot::factory()->create([
            'service_id'   => $this->service->id,
            'starts_at'    => now()->subMinutes($minutes),
            'ends_at'      => now()->subMinutes($minutes)->addHour(),
            'capacity'     => 10,
            'booked_count' => 1,
            'status'       => 'available',
        ]);
    }

    private function makeConfirmedReservation(TimeSlot $slot): Reservation
    {
        return $this->makeReservation($slot, 'confirmed');
    }

    private function makeReservation(TimeSlot $slot, string $status): Reservation
    {
        return Reservation::create([
            'uuid'         => (string) Str::uuid(),
            'user_id'      => $this->user->id,
            'service_id'   => $this->service->id,
            'time_slot_id' => $slot->id,
            'status'       => $status,
            'requested_at' => now()->subMinutes(30),
            'checked_in_at'=> in_array($status, ['checked_in', 'partial_attendance']) ? now()->subMinutes(5) : null,
        ]);
    }
}
