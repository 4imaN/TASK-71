<?php

namespace Tests\Feature\Livewire;

use App\Http\Livewire\Dashboard\LearnerDashboardComponent;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\TimeSlot;
use App\Models\User;
use App\Models\UserFavorite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Livewire component test for LearnerDashboardComponent (item 24).
 */
class DashboardComponentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'learner', 'guard_name' => 'web']);
        $this->user = User::factory()->create(['status' => 'active']);
        $this->user->assignRole('learner');
    }

    public function test_dashboard_renders_for_authenticated_user(): void
    {
        Livewire::actingAs($this->user)
            ->test(LearnerDashboardComponent::class)
            ->assertStatus(200)
            ->assertSee('Welcome back');
    }

    public function test_dashboard_shows_upcoming_reservations(): void
    {
        $service = Service::factory()->create(['status' => 'active']);
        $slot    = TimeSlot::factory()->create([
            'service_id' => $service->id,
            'starts_at'  => now()->addDays(2),
            'ends_at'    => now()->addDays(2)->addHour(),
        ]);

        Reservation::create([
            'uuid'         => (string) Str::uuid(),
            'user_id'      => $this->user->id,
            'service_id'   => $service->id,
            'time_slot_id' => $slot->id,
            'status'       => 'confirmed',
            'requested_at' => now(),
        ]);

        Livewire::actingAs($this->user)
            ->test(LearnerDashboardComponent::class)
            ->assertSee($service->title);
    }

    public function test_dashboard_shows_saved_services(): void
    {
        $service = Service::factory()->create(['status' => 'active']);

        UserFavorite::create([
            'user_id'    => $this->user->id,
            'service_id' => $service->id,
        ]);

        Livewire::actingAs($this->user)
            ->test(LearnerDashboardComponent::class)
            ->assertSee($service->title);
    }

    public function test_dashboard_shows_empty_state_when_no_data(): void
    {
        Livewire::actingAs($this->user)
            ->test(LearnerDashboardComponent::class)
            ->assertSee('No upcoming reservations');
    }
}
