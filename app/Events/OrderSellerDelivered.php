<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\OrderSeller;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

final class OrderSellerDelivered implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly OrderSeller $orderSeller) {}
}
