<?php

namespace Database\Factories;

use App\Models\Reservation;
use App\Models\Service;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Reservation> */
class ReservationFactory extends Factory
{
    protected $model = Reservation::class;

    public function definition(): array
    {
        return [
            'uuid'         => (string) Str::uuid(),
            'user_id'      => User::factory(),
            'service_id'   => Service::factory(),
            'time_slot_id' => TimeSlot::factory(),
            'status'       => 'confirmed',
            'requested_at' => now()->subMinutes(5),
            'confirmed_at' => now()->subMinutes(4),
            'expires_at'   => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status'       => 'pending',
            'confirmed_at' => null,
            'expires_at'   => now()->addMinutes(30),
        ]);
    }

    public function confirmed(): static
    {
        return $this->state(fn () => [
            'status'       => 'confirmed',
            'confirmed_at' => now()->subMinutes(4),
            'expires_at'   => null,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status'       => 'cancelled',
            'cancelled_at' => now(),
            'expires_at'   => null,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status'       => 'expired',
            'expires_at'   => now()->subMinutes(5),
        ]);
    }

    /** Pending with an already-passed expiry. */
    public function expiredPending(): static
    {
        return $this->state(fn () => [
            'status'       => 'pending',
            'confirmed_at' => null,
            'expires_at'   => now()->subMinutes(10),
        ]);
    }
}
