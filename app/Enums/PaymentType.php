<?php

namespace App\Enums;

enum PaymentType: string
{
    case Cash = 'cash';
    case Card = 'card';
    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::Cash => __('enums.payment_type.cash'),
            self::Card => __('enums.payment_type.card'),
            self::Both => __('enums.payment_type.both'),
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Cash => 'bg-emerald-500/10 text-emerald-400',
            self::Card => 'bg-blue-500/10 text-blue-400',
            self::Both => 'bg-violet-500/10 text-violet-400',
        };
    }
}
