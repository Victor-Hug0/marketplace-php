<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Dispute;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

final class DisputeOpened implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Dispute $dispute) {}
}
