<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentWebhookEventType: string
{
    case PAYMENT_PAID = 'payment.paid';
    case PAYMENT_FAILED = 'payment.failed';
}
