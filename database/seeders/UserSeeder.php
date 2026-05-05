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
            ['phone' => '998906591828'],
            [
                'name' => 'Супер Админ',
                'password' => Hash::make('840798792'),
                'role' => Role::SUPER_ADMIN,
            ]
        );

        User::updateOrCreate(
            ['phone' => '998991146881'],
            [
                'name' => 'Админ',
                'password' => Hash::make('4688112688'),
                'role' => Role::ADMIN,
            ]
        );
    }
}
