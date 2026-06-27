<?php

namespace Database\Seeders;

use App\Models\Barber;
use App\Models\Service;
use App\Models\Specialization;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(UserSeeder::class);

        $this->seedServices();

        $specializations = collect([
            'Топ-мастер', 'Барбер',
        ])->mapWithKeys(fn (string $name) => [
            $name => Specialization::factory()->create(['name' => $name]),
        ]);

        $barbers = [
            ['name' => 'Алексей Иванов', 'specialization' => 'Топ-мастер', 'price' => 150000],
            ['name' => 'Дмитрий Петров', 'specialization' => 'Барбер', 'price' => 100000],
        ];

        foreach ($barbers as $barber) {
            Barber::factory()->create([
                'name' => $barber['name'],
                'specialization_id' => $specializations[$barber['specialization']]->id,
                'price' => $barber['price'],
            ]);
        }
    }

    /**
     * Seed the base service catalogue with RU/UZ/KAA names.
     *
     * Idempotent: existing rows are matched by their Russian name and upgraded
     * to the full set of translations, so pre-translation data is not duplicated.
     */
    private function seedServices(): void
    {
        $baseServices = [
            ['ru' => 'Чистка лица', 'uz' => 'Yuz tozalash', 'kaa' => 'Bet tazalaw', 'duration_minutes' => 45],
            ['ru' => 'Шугаринг лица', 'uz' => 'Yuz shugaringi', 'kaa' => 'Betke shugaring', 'duration_minutes' => 30],
            ['ru' => 'Окрашивание бороды', 'uz' => 'Soqol boʻyash', 'kaa' => 'Saqal boyaw', 'duration_minutes' => 30],
            ['ru' => 'Коррекция бороды', 'uz' => 'Soqol tekislash', 'kaa' => 'Saqal tegislew', 'duration_minutes' => 30],
            ['ru' => 'Окрашивание волос', 'uz' => 'Soch boʻyash', 'kaa' => 'Shash boyaw', 'duration_minutes' => 60],
            ['ru' => 'Укладка', 'uz' => 'Soch turmagi', 'kaa' => 'Ukladka', 'duration_minutes' => 30],
            ['ru' => 'Мужская стрижка', 'uz' => 'Erkaklar soch olish', 'kaa' => 'Erler shashın aldırıw', 'duration_minutes' => 45],
        ];

        $existing = Service::all()->keyBy(fn (Service $service) => $service->translations['ru'] ?? '');

        foreach ($baseServices as $base) {
            $payload = [
                'name' => Service::encodeTranslations([
                    'ru' => $base['ru'],
                    'uz' => $base['uz'],
                    'kaa' => $base['kaa'],
                ]),
                'duration_minutes' => $base['duration_minutes'],
            ];

            if ($service = $existing->get($base['ru'])) {
                $service->update($payload);
            } else {
                Service::create($payload + ['is_active' => true]);
            }
        }
    }
}
