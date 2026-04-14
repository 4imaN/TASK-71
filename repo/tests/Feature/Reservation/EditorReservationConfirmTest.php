<?php

namespace Tests\Feature\Reservation;

use App\Http\Middleware\ValidateAppSession;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Tests for the editor/operator manual reservation management endpoints:
 *
 *   GET  /api/v1/editor/reservations              — pending queue
 *   POST /api/v1/editor/reservations/{id}/confirm — pending → confirmed
 *   POST /api/v1/editor/reservations/{id}/reject  — cancel on behalf of operator
 *
 * Coverage:
 *   - Pending queue lists only manual-confirm services
 *   - Queue is filterable by service_id and status
 *   - Confirm transitions pending → confirmed, returns 422 for non-pending
 *   - Reject cancels pending or confirmed, returns 422 for terminal states
 *   - Learner role is blocked from all editor endpoints (403)
 *   - Unauthenticated requests are rejected (401)
 */
class EditorReservationConfirmTest extends TestCase
{
    use RefreshDatabase;

    private User    $editor;
    private User    $learner;
    private Service $manualService;
    private Service $autoService;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'content_editor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'learner',        'guard_name' => 'web']);

        $this->editor = User::factory()->create();
        $this->editor->assignRole('content_editor');

        $this->learner = User::factory()->create();
        $this->learner->assignRole('learner');

        // A service that requires manual operator confirmation
        $this->manualService = Service::factory()->create([
            'status'                       => 'active',
            'requires_manual_confirmation' => true,
        ]);

        // A service that auto-confirms (should NOT appear in the pending queue)
        $this->autoService = Service::factory()->create([
            'status'                       => 'active',
            'requires_manual_confirmation' => false,
        ]);

        $this->withoutMiddleware(ValidateAppSession::class);
    }

    // ── Index — pending queue ─────────────────────────────────────────────────

    public function test_index_returns_pending_reservations_for_manual_services(): void
    {
        $slot        = $this->makeSlot($this->manualService);
        $pending     = $this->makeReservation($slot, $this->manualService, 'pending');
        $autoSlot    = $this->makeSlot($this->autoService);
        $autoConfirm = $this->makeReservation($autoSlot, $this->autoService, 'pending');

        $this->actingAs($this->editor);

        $response = $this->getJson('/api/v1/editor/reservations');

        $response->assertOk();

        // Only the manual-service reservation should appear
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($pending->id, $ids);
        $this->assertNotContains($autoConfirm->id, $ids);
    }

    public function test_index_defaults_to_pending_status(): void
    {
        $slot      = $this->makeSlot($this->manualService);
        $pending   = $this->makeReservation($slot, $this->manualService, 'pending');
        $confirmed = $this->makeReservation($slot, $this->manualService, 'confirmed');

        $this->actingAs($this->editor);

        $response = $this->getJson('/api/v1/editor/reservations');

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($pending->id, $ids);
        $this->assertNotContains($confirmed->id, $ids);
    }

    public function test_index_filters_by_status(): void
    {
        $slot      = $this->makeSlot($this->manualService);
        $pending   = $this->makeReservation($slot, $this->manualService, 'pending');
        $confirmed = $this->makeReservation($slot, $this->manualService, 'confirmed');

        $this->actingAs($this->editor);

        $response = $this->getJson('/api/v1/editor/reservations?status=confirmed');

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($confirmed->id, $ids);
        $this->assertNotContains($pending->id, $ids);
    }

    public function test_index_filters_by_service_id(): void
    {
        $otherManual = Service::factory()->create([
            'status'                       => 'active',
            'requires_manual_confirmation' => true,
        ]);
        $slot1 = $this->makeSlot($this->manualService);
        $slot2 = $this->makeSlot($otherManual);

        $r1 = $this->makeReservation($slot1, $this->manualService, 'pending');
        $r2 = $this->makeReservation($slot2, $otherManual, 'pending');

        $this->actingAs($this->editor);

        $response = $this->getJson("/api/v1/editor/reservations?service_id={$this->manualService->id}");

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($r1->id, $ids);
        $this->assertNotContains($r2->id, $ids);
    }

    // ── Confirm ───────────────────────────────────────────────────────────────

    public function test_confirm_transitions_pending_to_confirmed(): void
    {
        $slot        = $this->makeSlot($this->manualService);
        $reservation = $this->makeReservation($slot, $this->manualService, 'pending');

        $this->actingAs($this->editor);

        $response = $this->postJson("/api/v1/editor/reservations/{$reservation->id}/confirm");

        $response->assertOk()
                 ->assertJsonPath('reservation.status', 'confirmed');

        $this->assertDatabaseHas('reservations', [
            'id'     => $reservation->id,
            'status' => 'confirmed',
        ]);
        $this->assertNotNull($reservation->fresh()->confirmed_at);
    }

    public function test_confirm_returns_422_when_reservation_is_not_pending(): void
    {
        $slot        = $this->makeSlot($this->manualService);
        $reservation = $this->makeReservation($slot, $this->manualService, 'confirmed');

        $this->actingAs($this->editor);

        $response = $this->postJson("/api/v1/editor/reservations/{$reservation->id}/confirm");

        $response->assertStatus(422)
                 ->assertJsonPath('status', 'confirmed');
    }

    public function test_confirm_returns_422_for_cancelled_reservation(): void
    {
        $slot        = $this->makeSlot($this->manualService);
        $reservation = $this->makeReservation($slot, $this->manualService, 'cancelled');

        $this->actingAs($this->editor);

        $this->postJson("/api/v1/editor/reservations/{$reservation->id}/confirm")
             ->assertStatus(422);
    }

    public function test_confirm_returns_404_for_missing_reservation(): void
    {
        $this->actingAs($this->editor);

        $this->postJson('/api/v1/editor/reservations/9999999/confirm')
             ->assertNotFound();
    }

    // ── Reject ────────────────────────────────────────────────────────────────

    public function test_reject_cancels_a_pending_reservation(): void
    {
        $slot        = $this->makeSlot($this->manualService);
        $reservation = $this->makeReservation($slot, $this->manualService, 'pending');

        $this->actingAs($this->editor);

        $response = $this->postJson("/api/v1/editor/reservations/{$reservation->id}/reject");

        $response->assertOk()
                 ->assertJsonPath('reservation.status', 'cancelled');

        $this->assertDatabaseHas('reservations', [
            'id'     => $reservation->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_reject_cancels_a_confirmed_reservation(): void
    {
        $slot        = $this->makeSlot($this->manualService);
        $reservation = $this->makeReservation($slot, $this->manualService, 'confirmed');

        $this->actingAs($this->editor);

        $response = $this->postJson("/api/v1/editor/reservations/{$reservation->id}/reject");

        $response->assertOk()
                 ->assertJsonPath('reservation.status', 'cancelled');
    }

    public function test_reject_returns_422_for_already_cancelled_reservation(): void
    {
        $slot        = $this->makeSlot($this->manualService);
        $reservation = $this->makeReservation($slot, $this->manualService, 'cancelled');

        $this->actingAs($this->editor);

        $this->postJson("/api/v1/editor/reservations/{$reservation->id}/reject")
             ->assertStatus(422);
    }

    public function test_reject_returns_422_for_checked_out_reservation(): void
    {
        $slot        = $this->makeSlot($this->manualService);
        $reservation = $this->makeReservation($slot, $this->manualService, 'checked_out');

        $this->actingAs($this->editor);

        $this->postJson("/api/v1/editor/reservations/{$reservation->id}/reject")
             ->assertStatus(422);
    }

    public function test_reject_returns_404_for_missing_reservation(): void
    {
        $this->actingAs($this->editor);

        $this->postJson('/api/v1/editor/reservations/9999999/reject')
             ->assertNotFound();
    }

    // ── Role guard ────────────────────────────────────────────────────────────

    public function test_learner_cannot_access_editor_reservation_index(): void
    {
        $this->actingAs($this->learner);

        $this->getJson('/api/v1/editor/reservations')
             ->assertForbidden();
    }

    public function test_learner_cannot_confirm_reservations(): void
    {
        $slot        = $this->makeSlot($this->manualService);
        $reservation = $this->makeReservation($slot, $this->manualService, 'pending');

        $this->actingAs($this->learner);

        $this->postJson("/api/v1/editor/reservations/{$reservation->id}/confirm")
             ->assertForbidden();
    }

    public function test_learner_cannot_reject_reservations(): void
    {
        $slot        = $this->makeSlot($this->manualService);
        $reservation = $this->makeReservation($slot, $this->manualService, 'pending');

        $this->actingAs($this->learner);

        $this->postJson("/api/v1/editor/reservations/{$reservation->id}/reject")
             ->assertForbidden();
    }

    // ── Auth guard ────────────────────────────────────────────────────────────

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->app['auth']->logout();

        $slot        = $this->makeSlot($this->manualService);
        $reservation = $this->makeReservation($slot, $this->manualService, 'pending');

        foreach ([
            ['GET',  '/api/v1/editor/reservations'],
            ['POST', "/api/v1/editor/reservations/{$reservation->id}/confirm"],
            ['POST', "/api/v1/editor/reservations/{$reservation->id}/reject"],
        ] as [$method, $path]) {
            $this->json($method, $path)->assertUnauthorized();
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function makeSlot(Service $service): TimeSlot
    {
        return TimeSlot::factory()->create([
            'service_id'   => $service->id,
            'starts_at'    => now()->addDays(5),
            'ends_at'      => now()->addDays(5)->addHour(),
            'capacity'     => 10,
            'booked_count' => 1,
            'status'       => 'available',
        ]);
    }

    private function makeReservation(TimeSlot $slot, Service $service, string $status): Reservation
    {
        return Reservation::create([
            'uuid'         => (string) Str::uuid(),
            'user_id'      => $this->learner->id,
            'service_id'   => $service->id,
            'time_slot_id' => $slot->id,
            'status'       => $status,
            'requested_at' => now()->subMinutes(10),
        ]);
    }
}
