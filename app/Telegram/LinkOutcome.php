<?php

namespace App\Telegram;

enum LinkOutcome
{
    case BarberLinked;
    case ClientLinked;
    case ClientCreated;
    case InvalidPhone;
}
