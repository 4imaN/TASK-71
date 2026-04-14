<?php

namespace Tests\Feature\Reservation;

use App\Http\Middleware\ValidateAppSession;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tests for the learner reservation REST API:
 *   GET    /api/v1/reservations
 *   POST   /api/v1/reservations
 *   GET    /api/v1/reservations/{uuid}
 *   POST   /api/v1/reservations/{uuid}/cancel
 *   POST   /api/v1/reservations/{uuid}/reschedule
 *
 * Ownership isolation is enforced — one user cannot see or act on
 * another user's reservations.
 */
class ReservationApiTest extends TestCase
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
        $this->withoutMiddleware(ValidateAppSession::class);
    }

    // ── Index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_paginated_reservations(): void
    {
        $slot = $this->availableSlot();
        $this->makeReservation($slot, 'confirmed');
        $this->makeReservation($slot, 'cancelled');

        $response = $this->getJson('/api/v1/reservations');

        $response->assertOk()
                 ->assertJsonStructure(['data', 'total', 'per_page'])
                 ->assertJsonPath('total', 2);
    }

    public function test_index_filters_by_status(): void
    {
        $slot = $this->availableSlot();
        $this->makeReservation($slot, 'confirmed');
        $this->makeReservation($slot, 'cancelled');

        $response = $this->getJson('/api/v1/reservations?status=confirmed');

        $response->assertOk()->assertJsonPath('total', 1);
        $this->assertEquals('confirmed', $response->json('data.0.status'));
    }

    public function test_index_only_returns_own_reservations(): void
    {
        $other = User::factory()->create();
        $slot  = $this->availableSlot();
        Reservation::create([
            'uuid'         => (string) Str::uuid(),
            'user_id'      => $other->id,
            'service_id'   => $this->service->id,
            'time_slot_id' => $slot->id,
            'status'       => 'confirmed',
            'requested_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/reservations');

        $response->assertOk()->assertJsonPath('total', 0);
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    public function test_show_returns_reservation_with_policy_data(): void
    {
        $slot        = $this->availableSlot();
        $reservation = $this->makeReservation($slot, 'confirmed');

        $response = $this->getJson("/api/v1/reservations/{$reservation->uuid}");

        $response->assertOk()
                 ->assertJsonStructure([
                     'reservation',
                     'is_late_cancel',
                     'hours_until_slot',
                     'consequence',
                 ]);
    }

    public function test_show_returns_404_for_other_users_reservation(): void
    {
        $other = User::factory()->create();
        $slot  = $this->availableSlot();
        $r     = Reservation::create([
            'uuid'         => (string) Str::uuid(),
            'user_id'      => $other->id,
            'service_id'   => $this->service->id,
            'time_slot_id' => $slot->id,
            'status'       => 'confirmed',
            'requested_at' => now(),
        ]);

        $this->getJson("/api/v1/reservations/{$r->uuid}")
             ->assertNotFound();
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_reservation_and_returns_201(): void
    {
        $slot = $this->availableSlot();

        $response = $this->postJson('/api/v1/reservations', ['time_slot_id' => $slot->id]);

        $response->assertCreated()
                 ->assertJsonStructure(['reservation']);

        $this->assertDatabaseHas('reservations', [
            'user_id'      => $this->user->id,
            'time_slot_id' => $slot->id,
        ]);
    }

    public function test_store_returns_409_when_slot_is_full(): void
    {
        $slot = TimeSlot::factory()->create([
            'service_id'   => $this->service->id,
            'starts_at'    => now()->addDays(3),
            'ends_at'      => now()->addDays(3)->addHour(),
            'capacity'     => 2,
            'booked_count' => 2,
            'status'       => 'full',
        ]);

        $this->postJson('/api/v1/reservations', ['time_slot_id' => $slot->id])
             ->assertStatus(409);
    }

    public function test_store_returns_422_for_missing_time_slot_id(): void
    {
        $this->postJson('/api/v1/reservations', [])
             ->assertUnprocessable();
    }

    // ── Cancel ────────────────────────────────────────────────────────────────

    public function test_cancel_returns_updated_reservation(): void
    {
        $slot        = $this->slotFarFuture(booked: 1);
        $reservation = $this->makeReservation($slot, 'confirmed');

        $response = $this->postJson("/api/v1/reservations/{$reservation->uuid}/cancel");

        $response->assertOk()
                 ->assertJsonPath('reservation.status', 'cancelled');
    }

    public function test_cancel_returns_422_for_already_cancelled_reservation(): void
    {
        $slot        = $this->slotFarFuture(booked: 0);
        $reservation = $this->makeReservation($slot, 'cancelled');

        $this->postJson("/api/v1/reservations/{$reservation->uuid}/cancel")
             ->assertStatus(422);
    }

    public function test_cancel_returns_404_for_other_users_reservation(): void
    {
        $other = User::factory()->create();
        $slot  = $this->slotFarFuture(booked: 1);
        $r     = Reservation::create([
            'uuid'         => (string) Str::uuid(),
            'user_id'      => $other->id,
            'service_id'   => $this->service->id,
            'time_slot_id' => $slot->id,
            'status'       => 'confirmed',
            'requested_at' => now(),
        ]);

        $this->postJson("/api/v1/reservations/{$r->uuid}/cancel")
             ->assertNotFound();
    }

    // ── Reschedule ────────────────────────────────────────────────────────────

    public function test_reschedule_returns_new_reservation_with_201(): void
    {
        $oldSlot     = $this->slotFarFuture(booked: 1);
        $newSlot     = TimeSlot::factory()->create([
            'service_id'   => $this->service->id,
            'starts_at'    => now()->addDays(10),
            'ends_at'      => now()->addDays(10)->addHour(),
            'capacity'     => 10,
            'booked_count' => 0,
            'status'       => 'available',
        ]);
        $reservation = $this->makeReservation($oldSlot, 'confirmed');

        $response = $this->postJson("/api/v1/reservations/{$reservation->uuid}/reschedule", [
            'time_slot_id' => $newSlot->id,
        ]);

        $response->assertCreated()
                 ->assertJsonStructure(['reservation']);

        $this->assertEquals('rescheduled', $reservation->fresh()->status);
    }

    public function test_reschedule_returns_422_when_slot_belongs_to_different_service(): void
    {
        $otherService = Service::factory()->create(['status' => 'active']);
        $oldSlot      = $this->slotFarFuture(booked: 1);
        $otherSlot    = TimeSlot::factory()->create([
            'service_id'   => $otherService->id,
            'starts_at'    => now()->addDays(5),
            'ends_at'      => now()->addDays(5)->addHour(),
            'capacity'     => 10,
            'booked_count' => 0,
            'status'       => 'available',
        ]);
        $reservation  = $this->makeReservation($oldSlot, 'confirmed');

        $this->postJson("/api/v1/reservations/{$reservation->uuid}/reschedule", [
            'time_slot_id' => $otherSlot->id,
        ])->assertStatus(422);
    }

    // ── Auth guard ────────────────────────────────────────────────────────────

    public function test_reservation_endpoints_require_authentication(): void
    {
        $this->app['auth']->logout();

        foreach ([
            ['GET',  '/api/v1/reservations'],
            ['POST', '/api/v1/reservations'],
            ['GET',  '/api/v1/reservations/fake-uuid'],
            ['POST', '/api/v1/reservations/fake-uuid/cancel'],
            ['POST', '/api/v1/reservations/fake-uuid/reschedule'],
        ] as [$method, $path]) {
            $this->json($method, $path)->assertUnauthorized();
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function availableSlot(): TimeSlot
    {
        return TimeSlot::factory()->create([
            'service_id'   => $this->service->id,
            'starts_at'    => now()->addDays(3),
            'ends_at'      => now()->addDays(3)->addHour(),
            'capacity'     => 10,
            'booked_count' => 0,
            'status'       => 'available',
        ]);
    }

    private function slotFarFuture(int $booked = 1): TimeSlot
    {
        return TimeSlot::factory()->create([
            'service_id'   => $this->service->id,
            'starts_at'    => now()->addDays(7),
            'ends_at'      => now()->addDays(7)->addHour(),
            'capacity'     => 10,
            'booked_count' => $booked,
            'status'       => 'available',
        ]);
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
}
