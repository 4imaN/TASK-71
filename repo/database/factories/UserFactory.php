<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 *
 * Uses domain-aligned User model fields (username-based auth, no email).
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'uuid'                 => (string) Str::uuid(),
            'username'             => fake()->unique()->userName(),
            'display_name'         => fake()->name(),
            'password'             => Hash::make('TestPassword1!'),
            'password_changed_at'  => now(),
            'audience_type'        => fake()->randomElement(['faculty', 'staff', 'graduate_learner']),
            'status'               => 'active',
            'failed_attempts'      => 0,
            'must_change_password' => false,
            'remember_token'       => Str::random(10),
        ];
    }

    public function administrator(): static
    {
        return $this->state(fn () => ['audience_type' => 'staff']);
    }

    public function locked(): static
    {
        return $this->state(fn () => [
            'status'       => 'locked',
            'locked_until' => now()->addMinutes(15),
        ]);
    }
}
