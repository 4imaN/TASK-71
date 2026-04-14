<?php

namespace App\Services\Reservation;

use App\Models\TimeSlot;
use Illuminate\Support\Facades\DB;

/**
 * Manages slot capacity bookkeeping with pessimistic locking
 * to prevent over-booking under concurrent requests.
 */
class SlotAvailabilityService
{
    public function hasCapacity(TimeSlot $slot): bool
    {
        // Re-read with lock to check current capacity
        $fresh = TimeSlot::lockForUpdate()->find($slot->id);
        return $fresh && $fresh->booked_count < $fresh->capacity && $fresh->status === 'available';
    }

    public function incrementBookedCount(TimeSlot $slot): void
    {
        DB::table('time_slots')
            ->where('id', $slot->id)
            ->increment('booked_count');

        // Mark as full if capacity reached
        DB::table('time_slots')
            ->where('id', $slot->id)
            ->whereRaw('booked_count >= capacity')
            ->update(['status' => 'full']);
    }

    public function decrementBookedCount(TimeSlot $slot): void
    {
        DB::table('time_slots')
            ->where('id', $slot->id)
            ->where('booked_count', '>', 0)
            ->decrement('booked_count');

        // Reopen if was full
        DB::table('time_slots')
            ->where('id', $slot->id)
            ->where('status', 'full')
            ->whereRaw('booked_count < capacity')
            ->update(['status' => 'available']);
    }
}
