<?php

declare(strict_types=1);

namespace App\Enums;

enum DisputeStatus: string
{
    case OPEN = 'open';
    case RESOLVED_REFUNDED = 'resolved_refunded';
    case RESOLVED_REJECTED = 'resolved_rejected';
}
