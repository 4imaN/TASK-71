<?php

namespace App\Console\Commands;

use App\Models\Reservation;
use App\Services\Admin\SystemConfigService;
use App\Services\Reservation\ReservationService;
use Illuminate\Console\Command;

/**
 * Mark confirmed reservations as no-shows after their check-in window closes.
 *
 * A no-show is a 'confirmed' reservation whose slot started more than
 * checkin_closes_minutes_after (default 10) minutes ago without a check-in.
 *
 * For each no-show: transitions status to 'no_show', creates a NoShowBreach
 * record, and applies a 7-day booking freeze if the rolling breach count
 * reaches the configured threshold.
 *
 * Intended to run every minute via the scheduler.
 */
class MarkNoShowReservations extends Command
{
    protected $signature   = 'reservations:mark-noshows';
    protected $description = 'Mark confirmed reservations with a closed check-in window as no-shows and enforce the breach policy';

    public function handle(ReservationService $service, SystemConfigService $config): int
    {
        // Find confirmed reservations whose check-in window has already closed:
        // slot started more than `checkin_closes_minutes_after` minutes ago.
        $closeMins = $config->checkinClosedMinsAfterStart();
        $deadline  = now()->subMinutes($closeMins);

        $candidates = Reservation::where('status', 'confirmed')
            ->whereHas('timeSlot', fn ($q) => $q->where('starts_at', '<', $deadline))
            ->with(['timeSlot', 'user'])
            ->get();

        if ($candidates->isEmpty()) {
            $this->line('No no-show reservations to process.');
            return self::SUCCESS;
        }

        $count  = 0;
        $errors = 0;

        foreach ($candidates as $reservation) {
            try {
                $service->markNoShow($reservation);
                $count++;
            } catch (\Throwable $e) {
                $errors++;
                $this->warn("Failed to mark reservation #{$reservation->id} as no-show: {$e->getMessage()}");
            }
        }

        $this->info("Marked {$count} reservation(s) as no-show." . ($errors ? " {$errors} error(s)." : ''));

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
