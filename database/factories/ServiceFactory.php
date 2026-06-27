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
        $names = [
            ['ru' => 'Мужская стрижка', 'uz' => 'Soch olish (Erkaklar)', 'kaa' => 'Shash alıw (Er adamlar)'],
            ['ru' => 'Окрашивание бороды', 'uz' => 'Soqol boʻyash', 'kaa' => 'Saqal boyaw'],
            ['ru' => 'Коррекция бороды', 'uz' => 'Soqol tekislash', 'kaa' => 'Saqal tegislew'],
            ['ru' => 'Окрашивание волос', 'uz' => 'Soch boʻyash', 'kaa' => 'Shash boyaw'],
            ['ru' => 'Укладка', 'uz' => 'Soch turmagi', 'kaa' => 'Ukladka'],
        ];

        return [
            'name' => Service::encodeTranslations(fake()->randomElement($names)),
            'icon' => fake()->randomElement(Service::ICONS),
            'duration_minutes' => fake()->randomElement([30, 45, 60]),
            'is_active' => true,
        ];
    }
}
