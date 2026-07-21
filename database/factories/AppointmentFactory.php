<?php

namespace Database\Factories;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = Carbon::tomorrow()->setTime(fake()->numberBetween(10, 18), 0);
        $duration = fake()->randomElement([30, 45, 60]);

        return [
            'client_id' => Client::factory(),
            'barber_id' => Barber::factory(),
            'starts_at' => $startsAt,
            'ends_at' => (clone $startsAt)->addMinutes($duration),
            'status' => AppointmentStatus::Pending,
            'notified_30min' => false,
        ];
    }
}
