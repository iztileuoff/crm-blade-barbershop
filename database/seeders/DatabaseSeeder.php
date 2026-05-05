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
            'name' => 'Супер Админ',
            'phone' => '998901234567',
            'role' => \App\Enums\Role::SUPER_ADMIN,
        ]);

        $services = [
            ['name' => 'Мужская стрижка', 'duration_minutes' => 45],
            ['name' => 'Стрижка бороды', 'duration_minutes' => 30],
            ['name' => 'Стрижка + борода', 'duration_minutes' => 60],
            ['name' => 'Королевское бритьё', 'duration_minutes' => 45],
            ['name' => 'Детская стрижка', 'duration_minutes' => 30],
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
            ['name' => 'Алексей Иванов', 'specialization' => 'Топ-мастер', 'price' => 150000],
            ['name' => 'Дмитрий Петров', 'specialization' => 'Барбер', 'price' => 100000],
            ['name' => 'Иван Смирнов', 'specialization' => 'Стилист', 'price' => 120000],
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
