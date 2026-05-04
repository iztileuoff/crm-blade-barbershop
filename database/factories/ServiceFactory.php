<?php

namespace Database\Factories;

use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement([
                'Мужская стрижка', 'Стрижка бороды', 'Камуфляж седины',
                'Королевское бритьё', 'Детская стрижка',
            ]),
            'duration_minutes' => fake()->randomElement([30, 45, 60]),
            'price' => fake()->numberBetween(50_000, 250_000),
            'is_active' => true,
        ];
    }
}
