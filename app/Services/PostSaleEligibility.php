<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DisputeStatus;
use App\Enums\OrderSellerStatus;
use App\Enums\ReturnRequestStatus;
use App\Models\Dispute;
use App\Models\OrderSeller;
use App\Models\ReturnRequest;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PostSaleEligibility
{
    /**
     * Caller must hold a lock on the order seller inside a transaction.
     */
    public function assertBuyerCanOpen(User $buyer, OrderSeller $orderSeller, int $windowDays): void
    {
        $orderSeller->loadMissing('order:id,buyer_id');

        if ($orderSeller->order->buyer_id !== $buyer->id) {
            throw new NotFoundHttpException('Recurso não encontrado.');
        }

        if ($orderSeller->status !== OrderSellerStatus::DELIVERED) {
            throw new ConflictHttpException('Só é possível abrir pós-venda para subpedidos entregues.');
        }

        $deliveredAt = Shipment::query()
            ->where('order_seller_id', $orderSeller->id)
            ->value('delivered_at');

        if ($deliveredAt === null || now()->greaterThan(Carbon::parse($deliveredAt)->addDays($windowDays))) {
            throw new ConflictHttpException("O prazo de {$windowDays} dias após a entrega já terminou.");
        }

        $hasOpenDispute = Dispute::query()
            ->where('order_seller_id', $orderSeller->id)
            ->where('status', DisputeStatus::OPEN)
            ->exists();

        $hasPendingReturn = ReturnRequest::query()
            ->where('order_seller_id', $orderSeller->id)
            ->where('status', ReturnRequestStatus::REQUESTED)
            ->exists();

        if ($hasOpenDispute || $hasPendingReturn) {
            throw new ConflictHttpException('Já existe uma disputa ou devolução em andamento para este subpedido.');
        }
    }
}
