<?php

namespace Tests\Feature\Reservation;

use App\Exceptions\InvalidStateTransitionException;
use App\Exceptions\SlotUnavailableException;
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
 * Tests for the reschedule flow:
 *  - old reservation marked rescheduled, slot decremented
 *  - new reservation created with correct rescheduled_from_id
 *  - late-cancel consequence applied to original reservation when inside the policy window
 *  - no consequence when rescheduling outside the window
 *  - new slot capacity respected
 *  - guard: cannot reschedule to same slot
 *  - guard: cannot reschedule a cancelled reservation
 */
class ReservationRescheduleTest extends TestCase
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

    // ── Happy-path reschedule ─────────────────────────────────────────────────

    public function test_reschedule_marks_old_reservation_as_rescheduled(): void
    {
        [$oldSlot, $newSlot] = $this->twoSlots();
        $reservation = $this->makeConfirmedReservation($oldSlot);

        app(ReservationService::class)->reschedule($reservation, $newSlot, $this->user);

        $this->assertEquals('rescheduled', $reservation->fresh()->status);
    }

    public function test_reschedule_creates_new_reservation_linked_to_original(): void
    {
        [$oldSlot, $newSlot] = $this->twoSlots();
        $reservation = $this->makeConfirmedReservation($oldSlot);

        $newReservation = app(ReservationService::class)->reschedule($reservation, $newSlot, $this->user);

        $this->assertNotEquals($reservation->id, $newReservation->id);
        $this->assertEquals($reservation->id, $newReservation->rescheduled_from_id);
        $this->assertEquals($newSlot->id, $newReservation->time_slot_id);
    }

    public function test_reschedule_auto_confirms_new_reservation_for_non_manual_service(): void
    {
        [$oldSlot, $newSlot] = $this->twoSlots();
        $reservation = $this->makeConfirmedReservation($oldSlot);

        $newReservation = app(ReservationService::class)->reschedule($reservation, $newSlot, $this->user);

        $this->assertEquals('confirmed', $newReservation->status);
    }

    public function test_reschedule_decrements_old_slot_and_increments_new_slot(): void
    {
        [$oldSlot, $newSlot] = $this->twoSlots(oldBooked: 1, newBooked: 0);
        $reservation = $this->makeConfirmedReservation($oldSlot);

        app(ReservationService::class)->reschedule($reservation, $newSlot, $this->user);

        $this->assertEquals(0, $oldSlot->fresh()->booked_count);
        $this->assertEquals(1, $newSlot->fresh()->booked_count);
    }

    // ── Late-cancel consequence on reschedule ─────────────────────────────────

    public function test_reschedule_inside_window_applies_late_cancel_fee_to_original(): void
    {
        $this->setConfig('late_cancel_consequence_type', 'fee');
        $this->setConfig('late_cancel_fee_amount', '25.00');
        $this->setConfig('late_cancel_free_hours_before', '24');

        // Original slot starts in 12 hours — inside the late-cancel window
        $nearSlot = TimeSlot::factory()->create([
            'service_id'   => $this->service->id,
            'starts_at'    => now()->addHours(12),
            'ends_at'      => now()->addHours(13),
            'capacity'     => 10,
            'booked_count' => 1,
            'status'       => 'available',
        ]);
        $newSlot = TimeSlot::factory()->create([
            'service_id'   => $this->service->id,
            'starts_at'    => now()->addDays(5),
            'ends_at'      => now()->addDays(5)->addHour(),
            'capacity'     => 10,
            'booked_count' => 0,
            'status'       => 'available',
        ]);
        $reservation = $this->makeConfirmedReservation($nearSlot);

        app(ReservationService::class)->reschedule($reservation, $newSlot, $this->user);

        $reservation->refresh();
        $this->assertEquals('fee', $reservation->cancellation_consequence);
        $this->assertEquals('25.00', number_format($reservation->cancellation_consequence_amount, 2));
        // Reschedule inside window does NOT create a no-show breach
        $this->assertDatabaseMissing('no_show_breaches', ['reservation_id' => $reservation->id]);
    }

    public function test_reschedule_inside_window_applies_late_cancel_points_to_original(): void
    {
        $this->setConfig('late_cancel_consequence_type', 'points');
        $this->setConfig('late_cancel_points_amount', '50');
        $this->setConfig('late_cancel_free_hours_before', '24');

        $nearSlot = TimeSlot::factory()->create([
            'service_id'   => $this->service->id,
            'starts_at'    => now()->addHours(12),
            'ends_at'      => now()->addHours(13),
            'capacity'     => 10,
            'booked_count' => 1,
            'status'       => 'available',
        ]);
        $newSlot = TimeSlot::factory()->create([
            'service_id'   => $this->service->id,
            'starts_at'    => now()->addDays(5),
            'ends_at'      => now()->addDays(5)->addHour(),
            'capacity'     => 10,
            'booked_count' => 0,
            'status'       => 'available',
        ]);
        $reservation = $this->makeConfirmedReservation($nearSlot);

        app(ReservationService::class)->reschedule($reservation, $newSlot, $this->user);

        $reservation->refresh();
        $this->assertEquals('points', $reservation->cancellation_consequence);
        $this->assertDatabaseHas('points_ledger', ['user_id' => $this->user->id, 'amount' => -50]);
    }

    public function test_reschedule_outside_window_has_no_consequence(): void
    {
        // Original slot is 48h away — outside the default 24h window
        [$oldSlot, $newSlot] = $this->twoSlots();
        $reservation = $this->makeConfirmedReservation($oldSlot);

        app(ReservationService::class)->reschedule($reservation, $newSlot, $this->user);

        $reservation->refresh();
        $this->assertEquals('none', $reservation->cancellation_consequence);
        $this->assertDatabaseMissing('no_show_breaches', ['reservation_id' => $reservation->id]);
    }

    // ── Guards ────────────────────────────────────────────────────────────────

    public function test_reschedule_throws_when_new_slot_is_full(): void
    {
        [$oldSlot,] = $this->twoSlots();
        $fullSlot   = TimeSlot::factory()->create([
            'service_id'   => $this->service->id,
            'starts_at'    => now()->addDays(10),
            'ends_at'      => now()->addDays(10)->addHour(),
            'capacity'     => 2,
            'booked_count' => 2,
            'status'       => 'full',
        ]);
        $reservation = $this->makeConfirmedReservation($oldSlot);

        $this->expectException(SlotUnavailableException::class);

        app(ReservationService::class)->reschedule($reservation, $fullSlot, $this->user);
    }

    public function test_reschedule_throws_when_rescheduling_to_same_slot(): void
    {
        [$oldSlot,] = $this->twoSlots();
        $reservation = $this->makeConfirmedReservation($oldSlot);

        $this->expectException(InvalidStateTransitionException::class);

        app(ReservationService::class)->reschedule($reservation, $oldSlot, $this->user);
    }

    public function test_reschedule_throws_for_cancelled_reservation(): void
    {
        [$oldSlot, $newSlot] = $this->twoSlots();
        $reservation = $this->makeReservation($oldSlot, 'cancelled');

        $this->expectException(InvalidStateTransitionException::class);

        app(ReservationService::class)->reschedule($reservation, $newSlot, $this->user);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /** Returns [oldSlot, newSlot] with given booked counts. */
    private function twoSlots(int $oldBooked = 1, int $newBooked = 0): array
    {
        $old = TimeSlot::factory()->create([
            'service_id'   => $this->service->id,
            'starts_at'    => now()->addDays(3),
            'ends_at'      => now()->addDays(3)->addHour(),
            'capacity'     => 10,
            'booked_count' => $oldBooked,
            'status'       => 'available',
        ]);

        $new = TimeSlot::factory()->create([
            'service_id'   => $this->service->id,
            'starts_at'    => now()->addDays(5),
            'ends_at'      => now()->addDays(5)->addHour(),
            'capacity'     => 10,
            'booked_count' => $newBooked,
            'status'       => 'available',
        ]);

        return [$old, $new];
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
            'requested_at' => now()->subMinutes(5),
        ]);
    }

    private function setConfig(string $key, string $value): void
    {
        Cache::forget('sysconfig:' . $key);
        SystemConfig::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
