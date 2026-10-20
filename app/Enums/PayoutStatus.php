<?php

declare(strict_types=1);

namespace App\Enums;

enum PayoutStatus: string
{
    case REQUESTED = 'requested';
    case PAID = 'paid';
    case FAILED = 'failed';
}
