<?php

namespace App\Services\Editor;

use App\Exceptions\InvalidStateTransitionException;
use App\Models\Service;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Str;

/**
 * Business logic for content editors to create, update, and cancel
 * time slots attached to a service.
 *
 * All write operations are audited.
 */
class SlotEditorService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Create a new time slot for the given service.
     * Initial status is 'available', booked_count is 0.
     */
    public function createSlot(Service $service, User $editor, array $data): TimeSlot
    {
        $slot = TimeSlot::create([
            'uuid'         => (string) Str::uuid(),
            'service_id'   => $service->id,
            'starts_at'    => $data['starts_at'],
            'ends_at'      => $data['ends_at'],
            'capacity'     => $data['capacity'],
            'booked_count' => 0,
            'status'       => 'available',
            'created_by'   => $editor->id,
            'updated_by'   => $editor->id,
        ]);

        $this->auditLogger->log(
            action: 'slot.created',
            actorId: $editor->id,
            entityType: 'time_slot',
            entityId: $slot->id,
            afterState: $slot->toArray(),
        );

        return $slot;
    }

    /**
     * Update a time slot.
     * Throws InvalidStateTransitionException if:
     *   - slot is cancelled
     *   - new capacity is less than current booked_count
     */
    public function updateSlot(TimeSlot $slot, User $editor, array $data): TimeSlot
    {
        if ($slot->status === 'cancelled') {
            throw new InvalidStateTransitionException(
                "Cannot update a cancelled slot (id={$slot->id})."
            );
        }

        if (array_key_exists('capacity', $data) && (int) $data['capacity'] < $slot->booked_count) {
            throw new InvalidStateTransitionException(
                "New capacity ({$data['capacity']}) cannot be less than booked count ({$slot->booked_count})."
            );
        }

        $beforeState = $slot->toArray();

        $allowed = ['starts_at', 'ends_at', 'capacity'];
        $updates = [];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $data[$field];
            }
        }

        $updates['updated_by'] = $editor->id;

        $slot->update($updates);
        $slot->refresh();

        $this->auditLogger->log(
            action: 'slot.updated',
            actorId: $editor->id,
            entityType: 'time_slot',
            entityId: $slot->id,
            beforeState: $beforeState,
            afterState: $slot->toArray(),
        );

        return $slot;
    }

    /**
     * Cancel a time slot.
     * Throws InvalidStateTransitionException if the slot has pending or confirmed reservations.
     * Idempotent if already cancelled.
     */
    public function cancelSlot(TimeSlot $slot, User $editor): TimeSlot
    {
        if ($slot->status === 'cancelled') {
            return $slot;
        }

        $activeBookings = $slot->reservations()
            ->whereIn('status', ['pending', 'confirmed'])
            ->exists();

        if ($activeBookings) {
            throw new InvalidStateTransitionException(
                "Cannot cancel slot (id={$slot->id}) with active (pending or confirmed) reservations."
            );
        }

        $beforeState = $slot->toArray();

        $slot->update([
            'status'     => 'cancelled',
            'updated_by' => $editor->id,
        ]);

        $slot->refresh();

        $this->auditLogger->log(
            action: 'slot.cancelled',
            actorId: $editor->id,
            entityType: 'time_slot',
            entityId: $slot->id,
            beforeState: $beforeState,
            afterState: $slot->toArray(),
        );

        return $slot;
    }
}
