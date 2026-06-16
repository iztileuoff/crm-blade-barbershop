<?php

namespace App\Telegram;

enum AppointmentNotice
{
    case NewForBarber;
    case CancelledForBarber;
    case CancelledForClient;
}
