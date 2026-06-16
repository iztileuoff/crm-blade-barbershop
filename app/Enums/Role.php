<?php

namespace App\Enums;

enum Role: string
{
    case SUPER_ADMIN = 'super_admin';
    case ADMIN = 'admin';
    case BARBER = 'barber';

    public function label(): string
    {
        return match ($this) {
            self::SUPER_ADMIN => __('enums.role.super_admin'),
            self::ADMIN => __('enums.role.admin'),
            self::BARBER => __('enums.role.barber'),
        };
    }
}
