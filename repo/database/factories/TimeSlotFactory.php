<?php

namespace Database\Factories;

use App\Models\Service;
use App\Models\TimeSlot;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<TimeSlot> */
class TimeSlotFactory extends Factory
{
    protected $model = TimeSlot::class;

    public function definition(): array
    {
        $startsAt = fake()->dateTimeBetween('+1 day', '+60 days');
        return [
            'uuid'         => (string) Str::uuid(),
            'service_id'   => Service::factory(),
            'starts_at'    => $startsAt,
            'ends_at'      => (clone $startsAt)->modify('+1 hour'),
            'capacity'     => 20,
            'booked_count' => 0,
            'status'       => 'available',
        ];
    }

    public function full(): static
    {
        return $this->state(fn () => ['booked_count' => 20, 'capacity' => 20]);
    }

    public function past(): static
    {
        return $this->state(function () {
            $startsAt = fake()->dateTimeBetween('-60 days', '-1 day');
            return [
                'starts_at' => $startsAt,
                'ends_at'   => (clone $startsAt)->modify('+1 hour'),
            ];
        });
    }
}
