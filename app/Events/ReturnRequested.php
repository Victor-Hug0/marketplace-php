<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\ReturnRequest;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

final class ReturnRequested implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly ReturnRequest $returnRequest) {}
}
