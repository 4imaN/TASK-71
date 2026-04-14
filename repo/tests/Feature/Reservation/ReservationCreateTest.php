<?php

namespace Tests\Feature\Reservation;

use App\Exceptions\BookingFrozenException;
use App\Exceptions\SlotUnavailableException;
use App\Http\Livewire\Catalog\ServiceDetailComponent;
use App\Http\Middleware\ValidateAppSession;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\Reservation\ReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Tests for reservation creation:
 *  - auto-confirm path (requires_manual_confirmation = false)
 *  - manual-confirm path (requires_manual_confirmation = true)
 *  - booking-freeze guard
 *  - slot-full guard
 *  - Livewire bookSlot action from service detail page
 */
class ReservationCreateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['audience_type' => null]);
        $this->actingAs($this->user);
    }

    // ── Auto-confirm path ─────────────────────────────────────────────────────

    public function test_create_auto_confirms_when_service_does_not_require_manual_confirmation(): void
    {
        $service = Service::factory()->create([
            'status'                       => 'active',
            'requires_manual_confirmation' => false,
        ]);
        $slot = TimeSlot::factory()->create([
            'service_id'   => $service->id,
            'capacity'     => 5,
            'booked_count' => 0,
            'status'       => 'available',
        ]);

        $reservationService = app(ReservationService::class);
        $reservation        = $reservationService->create($this->user, $slot);

        $this->assertEquals('confirmed', $reservation->status);
        $this->assertNotNull($reservation->confirmed_at);
        $this->assertNull($reservation->expires_at);

        // Slot count incremented (by confirm())
        $slot->refresh();
        $this->assertEquals(1, $slot->booked_count);
    }

    // ── Manual-confirm path ───────────────────────────────────────────────────

    public function test_create_stays_pending_for_manual_confirmation_service(): void
    {
        $service = Service::factory()->create([
            'status'                       => 'active',
            'requires_manual_confirmation' => true,
        ]);
        $slot = TimeSlot::factory()->create([
            'service_id'   => $service->id,
            'capacity'     => 5,
            'booked_count' => 0,
            'status'       => 'available',
        ]);

        $reservationService = app(ReservationService::class);
        $reservation        = $reservationService->create($this->user, $slot);

        $this->assertEquals('pending', $reservation->status);
        $this->assertNotNull($reservation->expires_at);

        // Slot count incremented for manual-confirm path too
        $slot->refresh();
        $this->assertEquals(1, $slot->booked_count);
    }

    // ── Guards ────────────────────────────────────────────────────────────────

    public function test_create_throws_when_booking_frozen(): void
    {
        $this->user->update(['booking_freeze_until' => now()->addDays(7)]);

        $service = Service::factory()->create(['status' => 'active']);
        $slot    = TimeSlot::factory()->create(['service_id' => $service->id, 'status' => 'available']);

        $this->expectException(BookingFrozenException::class);

        app(ReservationService::class)->create($this->user->fresh(), $slot);
    }

    public function test_create_throws_when_slot_is_full(): void
    {
        $service = Service::factory()->create(['status' => 'active']);
        $slot    = TimeSlot::factory()->create([
            'service_id'   => $service->id,
            'capacity'     => 2,
            'booked_count' => 2,
            'status'       => 'full',
        ]);

        $this->expectException(SlotUnavailableException::class);

        app(ReservationService::class)->create($this->user, $slot);
    }

    // ── Status history ────────────────────────────────────────────────────────

    public function test_create_writes_status_history_record(): void
    {
        $service = Service::factory()->create([
            'status'                       => 'active',
            'requires_manual_confirmation' => true,
        ]);
        $slot = TimeSlot::factory()->create([
            'service_id'   => $service->id,
            'capacity'     => 5,
            'booked_count' => 0,
            'status'       => 'available',
        ]);

        $reservation = app(ReservationService::class)->create($this->user, $slot);

        $this->assertDatabaseHas('reservation_status_history', [
            'reservation_id' => $reservation->id,
            'from_status'    => null,
            'to_status'      => 'pending',
            'actor_type'     => 'user',
        ]);
    }

    // ── Livewire bookSlot ─────────────────────────────────────────────────────

    public function test_livewire_book_slot_redirects_on_success(): void
    {
        $service = Service::factory()->create([
            'status'                       => 'active',
            'requires_manual_confirmation' => false,
        ]);
        $slot = TimeSlot::factory()->create([
            'service_id'   => $service->id,
            'capacity'     => 5,
            'booked_count' => 0,
            'status'       => 'available',
        ]);

        Livewire::test(ServiceDetailComponent::class, ['slug' => $service->slug])
            ->call('bookSlot', $slot->id)
            ->assertRedirect();

        $this->assertDatabaseHas('reservations', [
            'user_id'      => $this->user->id,
            'service_id'   => $service->id,
            'time_slot_id' => $slot->id,
        ]);
    }

    public function test_livewire_book_slot_sets_error_when_slot_full(): void
    {
        $service = Service::factory()->create(['status' => 'active']);
        $slot    = TimeSlot::factory()->create([
            'service_id'   => $service->id,
            'capacity'     => 2,
            'booked_count' => 2,
            'status'       => 'full',
        ]);

        Livewire::test(ServiceDetailComponent::class, ['slug' => $service->slug])
            ->call('bookSlot', $slot->id)
            ->assertSet('bookError', fn ($v) => strlen($v) > 0);
    }
}
