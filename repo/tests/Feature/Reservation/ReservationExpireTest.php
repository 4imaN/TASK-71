<?php

namespace Tests\Feature\Reservation;

use App\Console\Commands\ExpirePendingReservations;
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
 * Tests for reservation expiry:
 *  - service-level expire() method
 *  - artisan command picks up stale pending and leaves fresh pending alone
 *  - slot count is decremented on expiry
 */
class ReservationExpireTest extends TestCase
{
    use RefreshDatabase;

    private User    $user;
    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user    = User::factory()->create();
        $this->service = Service::factory()->create(['status' => 'active', 'requires_manual_confirmation' => true]);
        $this->actingAs($this->user);
    }

    // ── expire() service method ───────────────────────────────────────────────

    public function test_expire_transitions_pending_to_expired_status(): void
    {
        $slot        = $this->slot();
        $reservation = $this->makePendingReservation($slot, expiresAt: now()->subMinutes(5));

        app(ReservationService::class)->expire($reservation);

        $this->assertEquals('expired', $reservation->fresh()->status);
    }

    public function test_expire_decrements_slot_booked_count(): void
    {
        $slot        = $this->slot(booked: 1);
        $reservation = $this->makePendingReservation($slot, expiresAt: now()->subMinutes(5));

        app(ReservationService::class)->expire($reservation);

        $this->assertEquals(0, $slot->fresh()->booked_count);
    }

    public function test_expire_writes_status_history_record(): void
    {
        $slot        = $this->slot();
        $reservation = $this->makePendingReservation($slot, expiresAt: now()->subMinutes(5));

        app(ReservationService::class)->expire($reservation);

        $this->assertDatabaseHas('reservation_status_history', [
            'reservation_id' => $reservation->id,
            'from_status'    => 'pending',
            'to_status'      => 'expired',
            'actor_type'     => 'system',
        ]);
    }

    public function test_expire_throws_for_non_pending_reservation(): void
    {
        $slot        = $this->slot();
        $reservation = Reservation::create([
            'uuid'         => (string) Str::uuid(),
            'user_id'      => $this->user->id,
            'service_id'   => $this->service->id,
            'time_slot_id' => $slot->id,
            'status'       => 'confirmed',
            'requested_at' => now(),
        ]);

        $this->expectException(InvalidStateTransitionException::class);

        app(ReservationService::class)->expire($reservation);
    }

    // ── Artisan command ───────────────────────────────────────────────────────

    public function test_command_expires_stale_pending_reservations(): void
    {
        $slot = $this->slot(booked: 1);

        // Stale pending (expires_at in the past)
        $stale = $this->makePendingReservation($slot, expiresAt: now()->subMinutes(10));

        $this->artisan(ExpirePendingReservations::class)->assertSuccessful();

        $this->assertEquals('expired', $stale->fresh()->status);
    }

    public function test_command_leaves_fresh_pending_reservations_alone(): void
    {
        $slot = $this->slot(booked: 1);

        // Fresh pending (expires_at in the future)
        $fresh = $this->makePendingReservation($slot, expiresAt: now()->addMinutes(20));

        $this->artisan(ExpirePendingReservations::class)->assertSuccessful();

        $this->assertEquals('pending', $fresh->fresh()->status);
    }

    public function test_command_reports_no_reservations_when_none_due(): void
    {
        $this->artisan(ExpirePendingReservations::class)
             ->expectsOutput('No pending reservations to expire.')
             ->assertSuccessful();
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function slot(int $booked = 0): TimeSlot
    {
        return TimeSlot::factory()->create([
            'service_id'   => $this->service->id,
            'starts_at'    => now()->addDays(3),
            'ends_at'      => now()->addDays(3)->addHour(),
            'capacity'     => 10,
            'booked_count' => $booked,
            'status'       => 'available',
        ]);
    }

    private function makePendingReservation(TimeSlot $slot, \DateTimeInterface $expiresAt): Reservation
    {
        return Reservation::create([
            'uuid'         => (string) Str::uuid(),
            'user_id'      => $this->user->id,
            'service_id'   => $this->service->id,
            'time_slot_id' => $slot->id,
            'status'       => 'pending',
            'requested_at' => now()->subMinutes(30),
            'expires_at'   => $expiresAt,
        ]);
    }
}
