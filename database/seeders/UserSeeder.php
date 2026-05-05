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
            ['phone' => '998999999999'],
            [
                'name' => 'Супер Админ',
                'password' => Hash::make('11223344'),
                'role' => Role::SUPER_ADMIN,
            ]
        );

        User::updateOrCreate(
            ['phone' => '998888888888'],
            [
                'name' => 'Админ',
                'password' => Hash::make('11223344'),
                'role' => Role::ADMIN,
            ]
        );
    }
}
