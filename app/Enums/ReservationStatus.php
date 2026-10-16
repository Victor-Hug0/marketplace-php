<?php

declare(strict_types=1);

namespace App\Enums;

enum ReservationStatus: string
{
    case ACTIVE = 'active';
    case RELEASED = 'released';
    case COMMITTED = 'committed';
}
