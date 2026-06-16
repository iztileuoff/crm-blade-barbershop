<?php

namespace Database\Factories;

use App\Models\SmsMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SmsMessage>
 */
class SmsMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => null,
            'phone' => '998'.fake()->numerify('#########'),
            'message' => fake()->sentence(),
            'status' => 'sent',
            'context' => fake()->randomElement(['reminder', 'retention', 'broadcast', 'manual']),
        ];
    }

    public function failed(): static
    {
        return $this->state(fn () => ['status' => 'failed']);
    }
}
