<?php

namespace App\Telegram;

enum AppointmentNotice
{
    case NewForBarber;
    case NewForClient;
    case ConfirmedForClient;
    case CancelledForBarber;
    case CancelledForClient;
}
