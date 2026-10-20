<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\InventoryService;
use Illuminate\Console\Command;

final class ReleaseExpiredReservations extends Command
{
    protected $signature = 'reservations:release-expired';

    protected $description = 'Release standalone stock reservations past their TTL';

    public function handle(InventoryService $inventoryService): int
    {
        $released = $inventoryService->releaseExpired();

        $this->info("{$released} reserva(s) liberada(s).");

        return self::SUCCESS;
    }
}
