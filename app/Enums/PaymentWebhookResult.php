<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentWebhookResult: string
{
    case APPLIED = 'applied';
    case ALREADY_PAID = 'already_paid';
    case PAYMENT_NOT_PENDING = 'payment_not_pending';
    case ORDER_NOT_PAYABLE = 'order_not_payable';
}
