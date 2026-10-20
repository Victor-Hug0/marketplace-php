<?php

declare(strict_types=1);

namespace App\Enums;

enum ShipmentStatus: string
{
    case IN_TRANSIT = 'in_transit';
    case DELIVERED = 'delivered';
}
