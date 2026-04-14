<?php

namespace Tests\Feature\Reservation;

use App\Console\Commands\MarkNoShowReservations;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\BookingFreeze;
use App\Models\NoShowBreach;
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
 * Tests for no-show detection and the breach/freeze policy:
 *  - markNoShow() transitions status and creates a breach record
 *  - only no_show breach_type counts toward the threshold
 *  - freeze is applied when threshold is reached
 *  - freeze is not applied below threshold
 *  - freeze extends if a later no-show pushes it further
 *  - artisan command marks correct reservations; ignores checked-in / future
 */
class ReservationNoShowTest extends TestCase
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

    // ── markNoShow() ──────────────────────────────────────────────────────────

    public function test_mark_noshow_transitions_confirmed_to_no_show(): void
    {
        $slot        = $this->slotWithClosedWindow();
        $reservation = $this->makeConfirmedReservation($slot);

        app(ReservationService::class)->markNoShow($reservation);

        $this->assertEquals('no_show', $reservation->fresh()->status);
    }

    public function test_mark_noshow_creates_noshow_breach_record(): void
    {
        $slot        = $this->slotWithClosedWindow();
        $reservation = $this->makeConfirmedReservation($slot);

        app(ReservationService::class)->markNoShow($reservation);

        $this->assertDatabaseHas('no_show_breaches', [
            'user_id'        => $this->user->id,
            'reservation_id' => $reservation->id,
            'breach_type'    => 'no_show',
        ]);
    }

    public function test_mark_noshow_writes_status_history(): void
    {
        $slot        = $this->slotWithClosedWindow();
        $reservation = $this->makeConfirmedReservation($slot);

        app(ReservationService::class)->markNoShow($reservation);

        $this->assertDatabaseHas('reservation_status_history', [
            'reservation_id' => $reservation->id,
            'from_status'    => 'confirmed',
            'to_status'      => 'no_show',
            'actor_type'     => 'system',
        ]);
    }

    public function test_mark_noshow_throws_if_window_still_open(): void
    {
        // Slot starts in 10 minutes — window not yet closed
        $slot = TimeSlot::factory()->create([
            'service_id'   => $this->service->id,
            'starts_at'    => now()->addMinutes(10),
            'ends_at'      => now()->addMinutes(70),
            'capacity'     => 10,
            'booked_count' => 1,
            'status'       => 'available',
        ]);
        $reservation = $this->makeConfirmedReservation($slot);

        $this->expectException(InvalidStateTransitionException::class);

        app(ReservationService::class)->markNoShow($reservation);
    }

    public function test_mark_noshow_throws_for_non_confirmed_status(): void
    {
        $slot        = $this->slotWithClosedWindow();
        $reservation = Reservation::create([
            'uuid'         => (string) Str::uuid(),
            'user_id'      => $this->user->id,
            'service_id'   => $this->service->id,
            'time_slot_id' => $slot->id,
            'status'       => 'cancelled',
            'requested_at' => now()->subHour(),
        ]);

        $this->expectException(InvalidStateTransitionException::class);

        app(ReservationService::class)->markNoShow($reservation);
    }

    // ── Breach counting ───────────────────────────────────────────────────────

    public function test_single_noshow_does_not_trigger_freeze(): void
    {
        $this->setConfig('noshow_breach_threshold', '2');
        $this->setConfig('noshow_breach_window_days', '60');

        $slot        = $this->slotWithClosedWindow();
        $reservation = $this->makeConfirmedReservation($slot);

        app(ReservationService::class)->markNoShow($reservation);

        $this->user->refresh();
        $this->assertNull($this->user->booking_freeze_until, 'Single no-show should not freeze the account');
        $this->assertDatabaseMissing('booking_freezes', ['user_id' => $this->user->id]);
    }

    public function test_two_noshows_within_window_trigger_freeze(): void
    {
        $this->setConfig('noshow_breach_threshold', '2');
        $this->setConfig('noshow_breach_window_days', '60');
        $this->setConfig('noshow_freeze_duration_days', '7');

        $reservationService = app(ReservationService::class);

        // First no-show
        $slot1 = $this->slotWithClosedWindow(startedMinutesAgo: 20);
        $r1    = $this->makeConfirmedReservation($slot1);
        $reservationService->markNoShow($r1);

        // Second no-show — crosses the threshold
        $slot2 = $this->slotWithClosedWindow(startedMinutesAgo: 15);
        $r2    = $this->makeConfirmedReservation($slot2);
        $reservationService->markNoShow($r2);

        $this->user->refresh();
        $this->assertNotNull($this->user->booking_freeze_until, 'Two no-shows should freeze the account');
        $this->assertTrue($this->user->booking_freeze_until->isFuture());

        $this->assertDatabaseHas('booking_freezes', ['user_id' => $this->user->id]);
    }

    public function test_only_noshow_breach_type_counts_toward_threshold(): void
    {
        // Add a late_cancel breach (should NOT count toward freeze threshold)
        NoShowBreach::create([
            'user_id'     => $this->user->id,
            'breach_type' => 'late_cancel',
            'occurred_at' => now()->subDays(1),
        ]);

        $this->setConfig('noshow_breach_threshold', '2');

        // One no_show breach — still below threshold
        $slot        = $this->slotWithClosedWindow();
        $reservation = $this->makeConfirmedReservation($slot);
        app(ReservationService::class)->markNoShow($reservation);

        $this->user->refresh();
        $this->assertNull($this->user->booking_freeze_until,
            'late_cancel breaches must not count toward the no-show freeze threshold'
        );
    }

    public function test_noshow_breach_outside_rolling_window_does_not_count(): void
    {
        $this->setConfig('noshow_breach_threshold', '2');
        $this->setConfig('noshow_breach_window_days', '60');

        // Old no-show breach (outside 60-day window)
        NoShowBreach::create([
            'user_id'     => $this->user->id,
            'breach_type' => 'no_show',
            'occurred_at' => now()->subDays(61),
        ]);

        // One fresh no-show — still below threshold because old breach is out of window
        $slot        = $this->slotWithClosedWindow();
        $reservation = $this->makeConfirmedReservation($slot);
        app(ReservationService::class)->markNoShow($reservation);

        $this->user->refresh();
        $this->assertNull($this->user->booking_freeze_until,
            'Breaches outside the rolling window should not count toward the threshold'
        );
    }

    public function test_freeze_extends_if_new_noshow_would_push_end_date_further(): void
    {
        $this->setConfig('noshow_breach_threshold', '2');
        $this->setConfig('noshow_breach_window_days', '60');
        $this->setConfig('noshow_freeze_duration_days', '7');

        // Give the user an existing freeze that ends in 3 days
        $this->user->update(['booking_freeze_until' => now()->addDays(3)]);

        // Two new no-shows that cross threshold
        $reservationService = app(ReservationService::class);

        $slot1 = $this->slotWithClosedWindow(startedMinutesAgo: 20);
        $reservationService->markNoShow($this->makeConfirmedReservation($slot1));

        $slot2 = $this->slotWithClosedWindow(startedMinutesAgo: 15);
        $reservationService->markNoShow($this->makeConfirmedReservation($slot2));

        // New freeze should end ~7 days from now (further than the existing 3-day end)
        $this->user->refresh();
        $this->assertTrue(
            $this->user->booking_freeze_until->isAfter(now()->addDays(5)),
            'Freeze should be extended when a later no-show would push the end date further'
        );
    }

    // ── Audit log entries ─────────────────────────────────────────────────────

    public function test_mark_noshow_writes_breach_audit_log_entry(): void
    {
        $slot        = $this->slotWithClosedWindow();
        $reservation = $this->makeConfirmedReservation($slot);

        app(ReservationService::class)->markNoShow($reservation);

        $this->assertDatabaseHas('audit_logs', [
            'action'      => 'policy.noshow_breach_recorded',
            'actor_type'  => 'system',
            'entity_type' => 'user',
            'entity_id'   => $this->user->id,
        ]);
    }

    public function test_mark_noshow_writes_freeze_audit_log_entry_when_threshold_crossed(): void
    {
        $this->setConfig('noshow_breach_threshold', '2');
        $this->setConfig('noshow_breach_window_days', '60');
        $this->setConfig('noshow_freeze_duration_days', '7');

        $reservationService = app(ReservationService::class);

        $slot1 = $this->slotWithClosedWindow(startedMinutesAgo: 20);
        $reservationService->markNoShow($this->makeConfirmedReservation($slot1));

        $slot2 = $this->slotWithClosedWindow(startedMinutesAgo: 15);
        $reservationService->markNoShow($this->makeConfirmedReservation($slot2));

        $this->assertDatabaseHas('audit_logs', [
            'action'      => 'policy.booking_freeze_applied',
            'actor_type'  => 'system',
            'entity_type' => 'user',
            'entity_id'   => $this->user->id,
        ]);
    }

    public function test_mark_noshow_does_not_write_freeze_audit_log_below_threshold(): void
    {
        $this->setConfig('noshow_breach_threshold', '2');

        $slot        = $this->slotWithClosedWindow();
        $reservation = $this->makeConfirmedReservation($slot);

        app(ReservationService::class)->markNoShow($reservation);

        $this->assertDatabaseMissing('audit_logs', [
            'action'     => 'policy.booking_freeze_applied',
            'entity_type'=> 'user',
            'entity_id'  => $this->user->id,
        ]);
    }

    // ── Artisan command ───────────────────────────────────────────────────────

    public function test_command_marks_reservations_with_closed_window(): void
    {
        $slot        = $this->slotWithClosedWindow();
        $reservation = $this->makeConfirmedReservation($slot);

        $this->artisan(MarkNoShowReservations::class)->assertSuccessful();

        $this->assertEquals('no_show', $reservation->fresh()->status);
    }

    public function test_command_ignores_reservations_with_open_window(): void
    {
        // Slot starts in 10 minutes — window still open
        $slot = TimeSlot::factory()->create([
            'service_id'   => $this->service->id,
            'starts_at'    => now()->addMinutes(10),
            'ends_at'      => now()->addMinutes(70),
            'capacity'     => 10,
            'booked_count' => 1,
            'status'       => 'available',
        ]);
        $reservation = $this->makeConfirmedReservation($slot);

        $this->artisan(MarkNoShowReservations::class)->assertSuccessful();

        $this->assertEquals('confirmed', $reservation->fresh()->status);
    }

    public function test_command_ignores_already_checked_in_reservations(): void
    {
        $slot = $this->slotWithClosedWindow();
        $reservation = Reservation::create([
            'uuid'          => (string) Str::uuid(),
            'user_id'       => $this->user->id,
            'service_id'    => $this->service->id,
            'time_slot_id'  => $slot->id,
            'status'        => 'checked_in',
            'requested_at'  => now()->subHour(),
            'checked_in_at' => now()->subMinutes(25),
        ]);

        $this->artisan(MarkNoShowReservations::class)->assertSuccessful();

        // Already checked in — status should remain checked_in
        $this->assertEquals('checked_in', $reservation->fresh()->status);
    }

    public function test_command_reports_no_reservations_when_none_due(): void
    {
        $this->artisan(MarkNoShowReservations::class)
             ->expectsOutput('No no-show reservations to process.')
             ->assertSuccessful();
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * A time slot whose check-in window has already closed.
     * Default: started 20 minutes ago → window closed (closes at start + 10 min).
     */
    private function slotWithClosedWindow(int $startedMinutesAgo = 20): TimeSlot
    {
        return TimeSlot::factory()->create([
            'service_id'   => $this->service->id,
            'starts_at'    => now()->subMinutes($startedMinutesAgo),
            'ends_at'      => now()->subMinutes($startedMinutesAgo)->addHour(),
            'capacity'     => 10,
            'booked_count' => 1,
            'status'       => 'available',
        ]);
    }

    private function makeConfirmedReservation(TimeSlot $slot): Reservation
    {
        return Reservation::create([
            'uuid'         => (string) Str::uuid(),
            'user_id'      => $this->user->id,
            'service_id'   => $this->service->id,
            'time_slot_id' => $slot->id,
            'status'       => 'confirmed',
            'requested_at' => now()->subHour(),
        ]);
    }

    private function setConfig(string $key, string $value): void
    {
        Cache::forget('sysconfig:' . $key);
        SystemConfig::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
