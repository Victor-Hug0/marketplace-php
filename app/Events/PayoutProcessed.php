<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Payout;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

final class PayoutProcessed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Payout $payout) {}
}
