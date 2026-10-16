<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderSellerStatus;
use App\Enums\OrderStatus;
use App\Enums\ReservationStatus;
use App\Models\AuditLog;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderSeller;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class OrderService
{
    public function __construct(
        private readonly OrderStateMachine $stateMachine,
        private readonly InventoryService $inventoryService,
    ) {}

    public function buyerOrdersQuery(User $buyer): Builder
    {
        return Order::query()
            ->where('buyer_id', $buyer->id)
            ->with([
                'sellers.store:id,store_name,slug,status,user_id',
                'sellers.items',
            ]);
    }

    public function adminOrdersQuery(): Builder
    {
        return Order::query()->with([
            'buyer:id,name,email',
            'sellers.store:id,store_name,slug,status,user_id',
            'sellers.items',
        ]);
    }

    public function sellerOrderSellersQuery(User $seller): Builder
    {
        return OrderSeller::query()
            ->whereHas('store', static fn (Builder $query): Builder => $query->where('user_id', $seller->id))
            ->with([
                'store:id,store_name,slug,status,user_id',
                'items',
                'order:id,buyer_id,status,currency,subtotal_cents,shipping_cents,total_cents,created_at',
            ]);
    }

    public function loadOrder(Order $order): Order
    {
        return $order->load([
            'sellers.store:id,store_name,slug,status,user_id',
            'sellers.items',
            'statusHistories',
            'sellers.statusHistories',
        ]);
    }

    public function loadOrderSeller(OrderSeller $orderSeller): OrderSeller
    {
        return $orderSeller->load([
            'store:id,store_name,slug,status,user_id',
            'items',
            'statusHistories',
            'order:id,buyer_id,status,currency,subtotal_cents,shipping_cents,total_cents,created_at',
        ]);
    }

    public function cancelByBuyer(User $buyer, Order $order, ?string $reason = null): Order
    {
        return DB::transaction(function () use ($buyer, $order, $reason): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->buyer_id !== $buyer->id) {
                throw new ConflictHttpException('Pedido não pertence ao comprador.');
            }

            if ($order->status !== OrderStatus::PENDING_PAYMENT) {
                throw new ConflictHttpException(
                    'O comprador só pode cancelar pedidos em pending_payment.',
                );
            }

            $this->transitionOrder(
                $order,
                OrderStatus::CANCELLED,
                $buyer,
                $reason ?? 'Cancelado pelo comprador.',
            );

            $sellers = OrderSeller::query()
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->get();

            foreach ($sellers as $seller) {
                if ($seller->status === OrderSellerStatus::CANCELLED) {
                    continue;
                }

                $this->transitionOrderSeller(
                    $seller,
                    OrderSellerStatus::CANCELLED,
                    $buyer,
                    $reason ?? 'Cancelado pelo comprador.',
                );
            }

            $this->releaseOrderReservations($order);
            $this->audit($buyer, $order, 'order.cancelled_by_buyer', [
                'reason' => $reason,
            ]);

            return $this->loadOrder($order->refresh());
        });
    }

    public function cancelBySeller(User $seller, OrderSeller $orderSeller, ?string $reason = null): OrderSeller
    {
        return DB::transaction(function () use ($seller, $orderSeller, $reason): OrderSeller {
            $orderSeller = OrderSeller::query()
                ->with('store')
                ->lockForUpdate()
                ->findOrFail($orderSeller->id);

            if ($orderSeller->store?->user_id !== $seller->id) {
                throw new ConflictHttpException('Subpedido não pertence à loja do vendedor.');
            }

            if (! $this->stateMachine->sellerCanCancel($orderSeller->status)) {
                throw new ConflictHttpException(
                    'O vendedor só pode cancelar subpedidos antes do envio.',
                );
            }

            $this->transitionOrderSeller(
                $orderSeller,
                OrderSellerStatus::CANCELLED,
                $seller,
                $reason ?? 'Cancelado pelo vendedor.',
            );

            $this->releaseOrderSellerReservations($orderSeller);

            $order = Order::query()->lockForUpdate()->findOrFail($orderSeller->order_id);
            $activeSellersRemain = OrderSeller::query()
                ->where('order_id', $order->id)
                ->where('status', '!=', OrderSellerStatus::CANCELLED)
                ->exists();

            if (! $activeSellersRemain && $order->status !== OrderStatus::CANCELLED) {
                $this->transitionOrder(
                    $order,
                    OrderStatus::CANCELLED,
                    $seller,
                    $reason ?? 'Todos os subpedidos foram cancelados.',
                );
                $this->releaseOrderReservations($order);
            }

            $this->audit($seller, $order, 'order_seller.cancelled_by_seller', [
                'order_seller_id' => $orderSeller->id,
                'reason' => $reason,
            ]);

            return $this->loadOrderSeller($orderSeller->refresh());
        });
    }

    public function transitionOrder(
        Order $order,
        OrderStatus $to,
        User $actor,
        ?string $reason = null,
    ): Order {
        $this->stateMachine->assertCanTransitionOrder($order->status, $to);

        $from = $order->status;
        $order->update(['status' => $to]);

        $this->recordHistory($order, $from->value, $to->value, $actor, $reason);

        return $order;
    }

    public function transitionOrderSeller(
        OrderSeller $orderSeller,
        OrderSellerStatus $to,
        User $actor,
        ?string $reason = null,
    ): OrderSeller {
        $this->stateMachine->assertCanTransitionOrderSeller($orderSeller->status, $to);

        $from = $orderSeller->status;
        $orderSeller->update(['status' => $to]);

        $this->recordHistory($orderSeller, $from->value, $to->value, $actor, $reason);

        return $orderSeller;
    }

    private function releaseOrderReservations(Order $order): void
    {
        $reservations = InventoryReservation::query()
            ->where('order_id', $order->id)
            ->where('status', ReservationStatus::ACTIVE)
            ->lockForUpdate()
            ->get();

        foreach ($reservations as $reservation) {
            $this->inventoryService->release($reservation);
        }
    }

    private function releaseOrderSellerReservations(OrderSeller $orderSeller): void
    {
        $offerIds = $orderSeller->items()->pluck('offer_id')->filter()->all();

        if ($offerIds === []) {
            return;
        }

        $reservations = InventoryReservation::query()
            ->where('order_id', $orderSeller->order_id)
            ->whereIn('offer_id', $offerIds)
            ->where('status', ReservationStatus::ACTIVE)
            ->lockForUpdate()
            ->get();

        foreach ($reservations as $reservation) {
            $this->inventoryService->release($reservation);
        }
    }

    private function recordHistory(
        Order|OrderSeller $statusable,
        ?string $fromStatus,
        string $toStatus,
        User $actor,
        ?string $reason = null,
    ): void {
        OrderStatusHistory::query()->create([
            'statusable_id' => $statusable->id,
            'statusable_type' => $statusable->getMorphClass(),
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_id' => $actor->id,
            'reason' => $reason,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function audit(User $actor, Order $order, string $action, array $payload = []): void
    {
        AuditLog::query()->create([
            'action' => $action,
            'actor_id' => $actor->id,
            'actor_type' => $actor->getMorphClass(),
            'auditable_id' => $order->id,
            'auditable_type' => $order->getMorphClass(),
            'payload' => $payload,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
        ]);
    }
}
