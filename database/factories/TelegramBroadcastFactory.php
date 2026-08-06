<?php

namespace Database\Factories;

use App\Models\TelegramBroadcast;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramBroadcast>
 */
class TelegramBroadcastFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $recipients = fake()->numberBetween(5, 200);
        $sent = fake()->numberBetween(0, $recipients);

        return [
            'user_id' => User::factory(),
            'audience' => fake()->randomElement(TelegramBroadcast::AUDIENCES),
            'message' => fake()->sentence(),
            'recipients_count' => $recipients,
            'sent_count' => $sent,
            'failed_count' => $recipients - $sent,
            'completed_at' => now(),
        ];
    }

    /**
     * Job ещё не отработал — счётчики нулевые, completed_at не проставлен.
     */
    public function pending(): static
    {
        return $this->state(fn () => [
            'sent_count' => 0,
            'failed_count' => 0,
            'completed_at' => null,
        ]);
    }
}
