<?php

namespace App\Enums;

enum Role: string
{
    case SUPER_ADMIN = 'super_admin';
    case ADMIN = 'admin';

    public function label(): string
    {
        return match($this) {
            self::SUPER_ADMIN => 'Супер Админ',
            self::ADMIN => 'Администратор',
        };
    }
}
