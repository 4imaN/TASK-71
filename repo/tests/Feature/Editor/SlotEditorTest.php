<?php

namespace Tests\Feature\Editor;

use App\Exceptions\InvalidStateTransitionException;
use App\Http\Middleware\ValidateAppSession;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\Editor\SlotEditorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SlotEditorTest extends TestCase
{
    use RefreshDatabase;

    private User $editor;
    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'content_editor', 'guard_name' => 'web']);

        $this->editor = User::factory()->create();
        $this->editor->assignRole('content_editor');

        $this->service = Service::factory()->create(['status' => 'active']);
    }

    private function slotEditorService(): SlotEditorService
    {
        return app(SlotEditorService::class);
    }

    // ── createSlot ────────────────────────────────────────────────────────────

    public function test_create_slot_returns_available_slot(): void
    {
        $slot = $this->slotEditorService()->createSlot($this->service, $this->editor, [
            'starts_at' => now()->addDay()->toDateTimeString(),
            'ends_at'   => now()->addDay()->addHour()->toDateTimeString(),
            'capacity'  => 20,
        ]);

        $this->assertInstanceOf(TimeSlot::class, $slot);
        $this->assertEquals('available', $slot->status);
        $this->assertEquals(0, $slot->booked_count);
        $this->assertEquals($this->service->id, $slot->service_id);
    }

    public function test_create_slot_with_future_time(): void
    {
        $startsAt = now()->addWeek();
        $endsAt   = $startsAt->copy()->addHours(2);

        $slot = $this->slotEditorService()->createSlot($this->service, $this->editor, [
            'starts_at' => $startsAt->toDateTimeString(),
            'ends_at'   => $endsAt->toDateTimeString(),
            'capacity'  => 5,
        ]);

        $this->assertTrue($slot->starts_at->isFuture());
        $this->assertTrue($slot->ends_at->isFuture());
    }

    // ── updateSlot ────────────────────────────────────────────────────────────

    public function test_update_slot_changes_capacity(): void
    {
        $slot = TimeSlot::factory()->create([
            'service_id'   => $this->service->id,
            'capacity'     => 10,
            'booked_count' => 0,
            'status'       => 'available',
        ]);

        $updated = $this->slotEditorService()->updateSlot($slot, $this->editor, [
            'capacity' => 25,
        ]);

        $this->assertEquals(25, $updated->capacity);
    }

    public function test_update_slot_throws_when_capacity_below_booked_count(): void
    {
        $slot = TimeSlot::factory()->create([
            'service_id'   => $this->service->id,
            'capacity'     => 10,
            'booked_count' => 8,
            'status'       => 'available',
        ]);

        $this->expectException(InvalidStateTransitionException::class);

        $this->slotEditorService()->updateSlot($slot, $this->editor, [
            'capacity' => 5,
        ]);
    }

    public function test_update_slot_throws_for_cancelled_slot(): void
    {
        $slot = TimeSlot::factory()->create([
            'service_id'   => $this->service->id,
            'capacity'     => 10,
            'booked_count' => 0,
            'status'       => 'cancelled',
        ]);

        $this->expectException(InvalidStateTransitionException::class);

        $this->slotEditorService()->updateSlot($slot, $this->editor, [
            'capacity' => 15,
        ]);
    }

    // ── cancelSlot ────────────────────────────────────────────────────────────

    public function test_cancel_slot_marks_as_cancelled(): void
    {
        $slot = TimeSlot::factory()->create([
            'service_id'   => $this->service->id,
            'capacity'     => 10,
            'booked_count' => 0,
            'status'       => 'available',
        ]);

        $cancelled = $this->slotEditorService()->cancelSlot($slot, $this->editor);

        $this->assertEquals('cancelled', $cancelled->status);
        $this->assertDatabaseHas('time_slots', ['id' => $slot->id, 'status' => 'cancelled']);
    }

    public function test_cancel_slot_throws_when_active_bookings_exist(): void
    {
        $slot = TimeSlot::factory()->create([
            'service_id'   => $this->service->id,
            'capacity'     => 10,
            'booked_count' => 1,
            'status'       => 'available',
        ]);

        // Create a confirmed reservation
        Reservation::factory()->create([
            'service_id'   => $this->service->id,
            'time_slot_id' => $slot->id,
            'status'       => 'confirmed',
        ]);

        $this->expectException(InvalidStateTransitionException::class);

        $this->slotEditorService()->cancelSlot($slot, $this->editor);
    }

    public function test_cancel_slot_is_idempotent(): void
    {
        $slot = TimeSlot::factory()->create([
            'service_id'   => $this->service->id,
            'capacity'     => 10,
            'booked_count' => 0,
            'status'       => 'cancelled',
        ]);

        // Should not throw
        $result = $this->slotEditorService()->cancelSlot($slot, $this->editor);

        $this->assertEquals('cancelled', $result->status);
    }

    // ── API tests ─────────────────────────────────────────────────────────────

    public function test_api_slot_create_returns_201(): void
    {
        $response = $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->editor)
            ->postJson("/api/v1/editor/services/{$this->service->id}/slots", [
                'starts_at' => now()->addDay()->toDateTimeString(),
                'ends_at'   => now()->addDay()->addHour()->toDateTimeString(),
                'capacity'  => 15,
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('slot.status', 'available');
    }

    public function test_api_slot_cancel_returns_200(): void
    {
        $slot = TimeSlot::factory()->create([
            'service_id'   => $this->service->id,
            'capacity'     => 10,
            'booked_count' => 0,
            'status'       => 'available',
        ]);

        $response = $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->editor)
            ->postJson("/api/v1/editor/services/{$this->service->id}/slots/{$slot->id}/cancel");

        $response->assertStatus(200);
        $response->assertJsonPath('slot.status', 'cancelled');
    }

    public function test_api_slot_cancel_returns_422_when_active_bookings(): void
    {
        $slot = TimeSlot::factory()->create([
            'service_id'   => $this->service->id,
            'capacity'     => 10,
            'booked_count' => 1,
            'status'       => 'available',
        ]);

        Reservation::factory()->create([
            'service_id'   => $this->service->id,
            'time_slot_id' => $slot->id,
            'status'       => 'confirmed',
        ]);

        $response = $this->withoutMiddleware(ValidateAppSession::class)
            ->actingAs($this->editor)
            ->postJson("/api/v1/editor/services/{$this->service->id}/slots/{$slot->id}/cancel");

        $response->assertStatus(422);
    }
}
