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
        $durations = [
            'Чистка лица' => 45,
            'Шугаринг лица' => 30,
            'Окрашивание бороды' => 30,
            'Коррекция бороды' => 30,
            'Окрашивание волос' => 60,
            'Укладка' => 30,
            'Мужская стрижка' => 45,
        ];

        $existing = Service::all()->keyBy(
            fn (Service $service) => mb_strtolower($service->translations['ru'] ?? '')
        );

        foreach (Service::baseCatalogue() as $entry) {
            $payload = [
                'name' => Service::encodeTranslations($entry),
                'duration_minutes' => $durations[$entry['ru']] ?? 30,
            ];

            if ($service = $existing->get(mb_strtolower($entry['ru']))) {
                $service->update($payload);
            } else {
                Service::create($payload + ['is_active' => true]);
            }
        }
    }
}
