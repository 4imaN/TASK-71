<?php

namespace App\Console\Commands;

use App\Models\Reservation;
use App\Services\Reservation\ReservationService;
use Illuminate\Console\Command;

/**
 * Expire stale pending reservations.
 *
 * Finds every reservation in 'pending' status whose expires_at is in the
 * past and transitions each one to 'expired' via ReservationService::expire().
 * This releases the held slot capacity so other learners can book.
 *
 * Intended to run frequently (e.g. every minute via the scheduler).
 */
class ExpirePendingReservations extends Command
{
    protected $signature   = 'reservations:expire-pending';
    protected $description = 'Transition stale pending reservations to expired and release their slots';

    public function handle(ReservationService $service): int
    {
        $expiring = Reservation::where('status', 'pending')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->with(['timeSlot'])
            ->get();

        if ($expiring->isEmpty()) {
            $this->line('No pending reservations to expire.');
            return self::SUCCESS;
        }

        $count  = 0;
        $errors = 0;

        foreach ($expiring as $reservation) {
            try {
                $service->expire($reservation);
                $count++;
            } catch (\Throwable $e) {
                $errors++;
                $this->warn("Failed to expire reservation #{$reservation->id}: {$e->getMessage()}");
            }
        }

        $this->info("Expired {$count} reservation(s)." . ($errors ? " {$errors} error(s)." : ''));

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
