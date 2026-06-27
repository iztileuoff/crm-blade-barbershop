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
            'eskiz_message_id' => (string) fake()->numberBetween(1000000, 9999999),
            'parts' => 1,
            'delivery_status' => null,
            'status_checked_at' => null,
        ];
    }

    public function failed(): static
    {
        return $this->state(fn () => ['status' => 'failed', 'eskiz_message_id' => null]);
    }

    public function delivered(): static
    {
        return $this->state(fn () => ['delivery_status' => 'delivered', 'status_checked_at' => now()]);
    }
}
