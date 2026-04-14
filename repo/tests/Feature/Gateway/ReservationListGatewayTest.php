<?php

namespace Tests\Feature\Gateway;

use App\Http\Livewire\Reservation\ReservationListComponent;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\Api\ReservationApiGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Verifies that ReservationApiGateway::list() is the shared contract for
 * reservation listing, consumed by both ReservationListComponent and
 * the REST ReservationController::index.
 */
class ReservationListGatewayTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    // ── Gateway unit-style tests ─────────────────────────────────────────────

    public function test_gateway_list_returns_user_reservations(): void
    {
        $service = Service::factory()->create(['status' => 'active']);
        $slot    = TimeSlot::factory()->create(['service_id' => $service->id]);

        Reservation::factory()->create([
            'user_id'      => $this->user->id,
            'service_id'   => $service->id,
            'time_slot_id' => $slot->id,
            'status'       => 'confirmed',
        ]);

        $gateway = app(ReservationApiGateway::class);
        $result  = $gateway->list($this->user, perPage: 10);

        $this->assertCount(1, $result->items());
    }

    public function test_gateway_list_scoped_to_user(): void
    {
        $other   = User::factory()->create();
        $service = Service::factory()->create(['status' => 'active']);
        $slot    = TimeSlot::factory()->create(['service_id' => $service->id]);

        Reservation::factory()->create([
            'user_id'      => $other->id,
            'service_id'   => $service->id,
            'time_slot_id' => $slot->id,
            'status'       => 'confirmed',
        ]);

        $gateway = app(ReservationApiGateway::class);
        $result  = $gateway->list($this->user, perPage: 10);

        $this->assertCount(0, $result->items());
    }

    public function test_gateway_list_filters_by_status(): void
    {
        $service = Service::factory()->create(['status' => 'active']);
        $slot    = TimeSlot::factory()->create(['service_id' => $service->id]);

        Reservation::factory()->create([
            'user_id'      => $this->user->id,
            'service_id'   => $service->id,
            'time_slot_id' => $slot->id,
            'status'       => 'confirmed',
        ]);

        Reservation::factory()->create([
            'user_id'      => $this->user->id,
            'service_id'   => $service->id,
            'time_slot_id' => $slot->id,
            'status'       => 'cancelled',
        ]);

        $gateway = app(ReservationApiGateway::class);

        $confirmed = $gateway->list($this->user, ['confirmed'], perPage: 10);
        $this->assertCount(1, $confirmed->items());
        $this->assertEquals('confirmed', $confirmed->items()[0]->status);

        $all = $gateway->list($this->user, perPage: 10);
        $this->assertCount(2, $all->items());
    }

    public function test_gateway_list_eager_loads_service_and_slot(): void
    {
        $service = Service::factory()->create(['title' => 'Eager Test', 'status' => 'active']);
        $slot    = TimeSlot::factory()->create(['service_id' => $service->id]);

        Reservation::factory()->create([
            'user_id'      => $this->user->id,
            'service_id'   => $service->id,
            'time_slot_id' => $slot->id,
            'status'       => 'confirmed',
        ]);

        $gateway     = app(ReservationApiGateway::class);
        $result      = $gateway->list($this->user, perPage: 10);
        $reservation = $result->items()[0];

        $this->assertTrue($reservation->relationLoaded('service'));
        $this->assertTrue($reservation->relationLoaded('timeSlot'));
    }

    // ── Livewire integration: ReservationListComponent uses the gateway ──────

    public function test_list_component_delegates_to_reservation_gateway(): void
    {
        $service = Service::factory()->create(['title' => 'Gateway List Test', 'status' => 'active']);
        $slot    = TimeSlot::factory()->create(['service_id' => $service->id]);

        Reservation::factory()->create([
            'user_id'      => $this->user->id,
            'service_id'   => $service->id,
            'time_slot_id' => $slot->id,
            'status'       => 'confirmed',
        ]);

        Livewire::test(ReservationListComponent::class)
            ->assertSee('Gateway List Test');
    }

    public function test_list_component_status_filter_uses_gateway(): void
    {
        $service = Service::factory()->create(['title' => 'Filtered Service', 'status' => 'active']);
        $slot    = TimeSlot::factory()->create(['service_id' => $service->id]);

        Reservation::factory()->create([
            'user_id'      => $this->user->id,
            'service_id'   => $service->id,
            'time_slot_id' => $slot->id,
            'status'       => 'cancelled',
        ]);

        Livewire::test(ReservationListComponent::class)
            ->set('statusFilter', 'confirmed')
            ->assertDontSee('Filtered Service');
    }
}
