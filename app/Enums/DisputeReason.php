<?php

declare(strict_types=1);

namespace App\Enums;

enum DisputeReason: string
{
    case NOT_RECEIVED = 'not_received';
    case NOT_AS_DESCRIBED = 'not_as_described';
    case DAMAGED = 'damaged';
    case OTHER = 'other';
}
