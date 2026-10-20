<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Order;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Unpaid order cancelled, either by the buyer or by the payment deadline (OrderCancelledNotification::CAUSE_*).
 */
final class OrderCancelled implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Order $order,
        public readonly string $cause,
    ) {}
}
