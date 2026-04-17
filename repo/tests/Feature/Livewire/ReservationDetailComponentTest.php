<?php

namespace Tests\Feature\Livewire;

use App\Http\Livewire\Reservation\ReservationDetailComponent;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Livewire component test for ReservationDetailComponent (item 24).
 */
class ReservationDetailComponentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Service $service;
    private TimeSlot $slot;
    private Reservation $reservation;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'learner', 'guard_name' => 'web']);

        $this->user    = User::factory()->create(['status' => 'active']);
        $this->user->assignRole('learner');

        $this->service = Service::factory()->create(['status' => 'active']);

        $this->slot = TimeSlot::factory()->create([
            'service_id' => $this->service->id,
            'starts_at'  => now()->addDays(7),
            'ends_at'    => now()->addDays(7)->addHour(),
            'capacity'   => 5,
        ]);

        $this->reservation = Reservation::create([
            'uuid'         => (string) Str::uuid(),
            'user_id'      => $this->user->id,
            'service_id'   => $this->service->id,
            'time_slot_id' => $this->slot->id,
            'status'       => 'confirmed',
            'requested_at' => now(),
        ]);
    }

    public function test_reservation_detail_renders(): void
    {
        Livewire::actingAs($this->user)
            ->test(ReservationDetailComponent::class, ['uuid' => $this->reservation->uuid])
            ->assertStatus(200)
            ->assertSee($this->service->title);
    }

    public function test_reservation_detail_shows_status_badge(): void
    {
        Livewire::actingAs($this->user)
            ->test(ReservationDetailComponent::class, ['uuid' => $this->reservation->uuid])
            ->assertSee('Confirmed');
    }

    public function test_reservation_detail_shows_cancel_button_for_confirmed(): void
    {
        Livewire::actingAs($this->user)
            ->test(ReservationDetailComponent::class, ['uuid' => $this->reservation->uuid])
            ->assertSee('Cancel reservation');
    }

    public function test_reservation_detail_hides_cancel_for_cancelled_reservation(): void
    {
        $this->reservation->update(['status' => 'cancelled']);

        Livewire::actingAs($this->user)
            ->test(ReservationDetailComponent::class, ['uuid' => $this->reservation->uuid])
            ->assertDontSee('Cancel reservation');
    }

    public function test_reservation_detail_opens_cancel_modal(): void
    {
        Livewire::actingAs($this->user)
            ->test(ReservationDetailComponent::class, ['uuid' => $this->reservation->uuid])
            ->call('openCancelModal')
            ->assertSet('showCancelModal', true);
    }

    public function test_other_user_cannot_see_reservation(): void
    {
        $other = User::factory()->create();

        // Should get a 404 since ownership is enforced
        Livewire::actingAs($other)
            ->test(ReservationDetailComponent::class, ['uuid' => $this->reservation->uuid])
            ->assertStatus(404);
    }
}
