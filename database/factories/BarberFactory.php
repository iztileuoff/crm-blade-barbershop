<?php

namespace Database\Factories;

use App\Models\Barber;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Barber>
 */
class BarberFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'specialization' => fake()->randomElement([
                'Барбер', 'Топ-мастер', 'Стилист', 'Бородовед',
            ]),
            'photo_path' => null,
            'schedule' => [
                'mon' => ['10:00', '20:00'],
                'tue' => ['10:00', '20:00'],
                'wed' => ['10:00', '20:00'],
                'thu' => ['10:00', '20:00'],
                'fri' => ['10:00', '20:00'],
                'sat' => ['10:00', '18:00'],
                'sun' => null,
            ],
            'is_active' => true,
        ];
    }
}
