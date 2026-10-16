<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderSellerStatus: string
{
    case PENDING_PAYMENT = 'pending_payment';
    case PAID = 'paid';
    case PROCESSING = 'processing';
    case SHIPPED = 'shipped';
    case DELIVERED = 'delivered';
    case CANCELLED = 'cancelled';
    case REFUNDED = 'refunded';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::DELIVERED,
            self::CANCELLED,
            self::REFUNDED,
        ], true);
    }
}
