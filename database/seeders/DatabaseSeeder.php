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
        User::factory()->create([
            'name' => 'Администратор',
            'email' => 'admin@barbershop.test',
        ]);

        $services = [
            ['name' => 'Мужская стрижка', 'duration_minutes' => 45, 'price' => 120000],
            ['name' => 'Стрижка бороды', 'duration_minutes' => 30, 'price' => 70000],
            ['name' => 'Стрижка + борода', 'duration_minutes' => 60, 'price' => 170000],
            ['name' => 'Королевское бритьё', 'duration_minutes' => 45, 'price' => 150000],
            ['name' => 'Детская стрижка', 'duration_minutes' => 30, 'price' => 90000],
        ];

        foreach ($services as $service) {
            Service::factory()->create($service);
        }

        $specializations = collect([
            'Топ-мастер', 'Барбер', 'Стилист', 'Бородовед',
        ])->mapWithKeys(fn (string $name) => [
            $name => Specialization::factory()->create(['name' => $name]),
        ]);

        $barbers = [
            ['name' => 'Алексей Иванов', 'specialization' => 'Топ-мастер'],
            ['name' => 'Дмитрий Петров', 'specialization' => 'Барбер'],
            ['name' => 'Иван Смирнов', 'specialization' => 'Стилист'],
        ];

        foreach ($barbers as $barber) {
            Barber::factory()->create([
                'name' => $barber['name'],
                'specialization_id' => $specializations[$barber['specialization']]->id,
            ]);
        }
    }
}
