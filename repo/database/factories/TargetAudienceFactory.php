<?php

namespace Database\Factories;

use App\Models\TargetAudience;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TargetAudience> */
class TargetAudienceFactory extends Factory
{
    protected $model = TargetAudience::class;

    public function definition(): array
    {
        $code = fake()->unique()->lexify('audience_????');
        return [
            'code'       => $code,
            'label'      => ucwords(str_replace('_', ' ', $code)),
            'is_active'  => true,
            'sort_order' => fake()->numberBetween(0, 99),
        ];
    }
}
