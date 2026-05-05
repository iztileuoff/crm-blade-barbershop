<?php

namespace Database\Seeders;

use App\Models\Barber;
use App\Models\Service;
use App\Models\Specialization;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(UserSeeder::class);

        $services = [
            ['name' => 'Мужская стрижка', 'duration_minutes' => 45],
        ];

        foreach ($services as $service) {
            Service::factory()->create($service);
        }

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
}
