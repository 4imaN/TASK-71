<?php

namespace Tests\Feature\Livewire;

use App\Http\Livewire\Editor\PendingConfirmationsComponent;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Livewire component test for PendingConfirmationsComponent (item 7).
 */
class PendingConfirmationsComponentTest extends TestCase
{
    use RefreshDatabase;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'content_editor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'learner',        'guard_name' => 'web']);

        $this->editor = User::factory()->create([
            'password' => Hash::make('secret123'),
        ]);
        $this->editor->assignRole('content_editor');
    }

    public function test_pending_confirmations_renders(): void
    {
        Livewire::actingAs($this->editor)
            ->test(PendingConfirmationsComponent::class)
            ->assertStatus(200);
    }

    public function test_pending_confirmations_shows_pending_reservations(): void
    {
        $service = Service::factory()->create([
            'status'                       => 'active',
            'requires_manual_confirmation' => true,
        ]);

        $slot = TimeSlot::factory()->create([
            'service_id' => $service->id,
            'starts_at'  => now()->addDays(3),
            'ends_at'    => now()->addDays(3)->addHour(),
            'capacity'   => 5,
        ]);

        $learner = User::factory()->create();
        $learner->assignRole('learner');

        Reservation::create([
            'uuid'         => (string) Str::uuid(),
            'user_id'      => $learner->id,
            'service_id'   => $service->id,
            'time_slot_id' => $slot->id,
            'status'       => 'pending',
            'requested_at' => now(),
        ]);

        Livewire::actingAs($this->editor)
            ->test(PendingConfirmationsComponent::class)
            ->assertSee($service->title);
    }

    public function test_pending_confirmations_confirm_action(): void
    {
        $service = Service::factory()->create([
            'status'                       => 'active',
            'requires_manual_confirmation' => true,
        ]);

        $slot = TimeSlot::factory()->create([
            'service_id' => $service->id,
            'starts_at'  => now()->addDays(3),
            'ends_at'    => now()->addDays(3)->addHour(),
            'capacity'   => 5,
        ]);

        $learner = User::factory()->create();

        $reservation = Reservation::create([
            'uuid'         => (string) Str::uuid(),
            'user_id'      => $learner->id,
            'service_id'   => $service->id,
            'time_slot_id' => $slot->id,
            'status'       => 'pending',
            'requested_at' => now(),
        ]);

        Livewire::actingAs($this->editor)
            ->test(PendingConfirmationsComponent::class)
            ->call('confirm', $reservation->id);

        $this->assertDatabaseHas('reservations', [
            'id'     => $reservation->id,
            'status' => 'confirmed',
        ]);
    }
}
