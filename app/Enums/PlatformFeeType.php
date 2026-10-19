<?php

declare(strict_types=1);

namespace App\Enums;

enum PlatformFeeType: string
{
    case PERCENTAGE = 'percentage';
    case FIXED = 'fixed';
}
