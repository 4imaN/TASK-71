<?php

namespace Database\Factories;

use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Service> */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        $title = fake()->unique()->sentence(4, false);
        return [
            'uuid'                       => (string) Str::uuid(),
            'slug'                       => Str::slug($title) . '-' . fake()->numerify('####'),
            'title'                      => $title,
            'description'                => fake()->paragraph(),
            'eligibility_notes'          => null,
            'category_id'                => null,
            'service_type_id'            => null,
            'is_free'                    => true,
            'fee_amount'                 => 0,
            'fee_currency'               => 'USD',
            'requires_manual_confirmation' => false,
            'status'                     => 'active',
        ];
    }

    public function paid(float $amount = 50.00): static
    {
        return $this->state(fn () => [
            'is_free'    => false,
            'fee_amount' => $amount,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => 'inactive']);
    }

    public function withCategory(): static
    {
        return $this->state(fn () => [
            'category_id' => ServiceCategory::factory(),
        ]);
    }
}
