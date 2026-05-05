<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['phone' => '998901234567'],
            [
                'name' => 'Супер Админ',
                'password' => Hash::make('password'),
                'role' => Role::SUPER_ADMIN,
            ]
        );
    }
}
