<?php

namespace Database\Factories;

use App\Models\Specialization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Specialization>
 */
class SpecializationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement([
                'Барбер', 'Топ-мастер', 'Стилист', 'Бородовед', 'Колорист',
            ]),
            'description' => null,
        ];
    }
}
