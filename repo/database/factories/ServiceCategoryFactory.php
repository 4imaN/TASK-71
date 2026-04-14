<?php

namespace Database\Factories;

use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ServiceCategory> */
class ServiceCategoryFactory extends Factory
{
    protected $model = ServiceCategory::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);
        return [
            'name'       => ucwords($name),
            'slug'       => Str::slug($name) . '-' . fake()->numerify('###'),
            'description'=> fake()->sentence(),
            'sort_order' => fake()->numberBetween(0, 99),
            'is_active'  => true,
        ];
    }
}
