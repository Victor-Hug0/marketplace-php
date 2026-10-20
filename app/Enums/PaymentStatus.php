<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentStatus: string
{
    case PENDING = 'pending';
    case PAID = 'paid';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    /**
     * @return list<string>
     */
    public static function openValues(): array
    {
        return [self::PENDING->value, self::PAID->value];
    }
}
