<?php

namespace Database\Factories;

use App\Models\Barber;
use App\Models\Specialization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Barber>
 */
class BarberFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'specialization_id' => Specialization::factory(),
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
