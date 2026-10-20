<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Console\Command;

final class ExpireUnpaidOrders extends Command
{
    protected $signature = 'orders:expire-unpaid {--limit=200 : Maximum orders to expire in one run}';

    protected $description = 'Cancel orders whose payment deadline passed and release their reserved stock';

    public function handle(OrderService $orderService): int
    {
        $expired = 0;

        $orderService->overdueUnpaidOrdersQuery()
            ->orderBy('payment_due_at')
            ->limit((int) $this->option('limit'))
            ->get(['id'])
            ->each(function (Order $order) use ($orderService, &$expired): void {
                if ($orderService->expireUnpaid($order)) {
                    $expired++;
                }
            });

        $this->info("{$expired} pedido(s) expirado(s).");

        return self::SUCCESS;
    }
}
