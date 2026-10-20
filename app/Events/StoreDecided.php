<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Store;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

final class StoreDecided implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Store $store) {}
}
