<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderSellerStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Models\AuditLog;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderSeller;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class OrderService
{
    public function __construct(
        private readonly OrderStateMachine $stateMachine,
        private readonly InventoryService $inventoryService,
        private readonly RefundService $refundService,
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
            'sellers.refunds',
            'statusHistories',
            'sellers.statusHistories',
        ]);
    }

    public function loadOrderSeller(OrderSeller $orderSeller): OrderSeller
    {
        return $orderSeller->load([
            'store:id,store_name,slug,status,user_id',
            'items',
            'refunds',
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
            $this->cancelPendingPayments($order);
            $this->audit($buyer, $order, 'order.cancelled_by_buyer', [
                'reason' => $reason,
            ]);

            return $this->loadOrder($order->refresh());
        });
    }

    public function cancelBySeller(User $seller, OrderSeller $orderSeller, ?string $reason = null): OrderSeller
    {
        return DB::transaction(function () use ($seller, $orderSeller, $reason): OrderSeller {
            $order = Order::query()->lockForUpdate()->findOrFail($orderSeller->order_id);

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

            $wasPaid = in_array($orderSeller->status, [
                OrderSellerStatus::PAID,
                OrderSellerStatus::PROCESSING,
            ], true);

            $this->transitionOrderSeller(
                $orderSeller,
                OrderSellerStatus::CANCELLED,
                $seller,
                $reason ?? 'Cancelado pelo vendedor.',
            );

            $this->releaseOrderSellerReservations($orderSeller);

            if ($order->status === OrderStatus::PENDING_PAYMENT) {
                $this->cancelPendingPayments($order);
            }

            $refund = null;
            if ($wasPaid && $this->refundService->remainingCents($orderSeller) > 0) {
                $refund = $this->refundService->issue(
                    $orderSeller,
                    $this->refundService->paidPaymentFor($orderSeller),
                    $this->refundService->remainingCents($orderSeller),
                    $seller,
                    $reason ?? 'Reembolso automático por cancelamento do vendedor.',
                    "seller-cancel:{$orderSeller->id}",
                    restockOnFullRefund: true,
                );
            }

            $this->syncOrderWithSellers(
                $order,
                $seller,
                $reason ?? 'Todos os subpedidos foram encerrados.',
            );

            $this->audit($seller, $order, 'order_seller.cancelled_by_seller', [
                'order_seller_id' => $orderSeller->id,
                'reason' => $reason,
                'refund_id' => $refund?->id,
                'refund_amount_cents' => $refund?->amount_cents,
            ]);

            return $this->loadOrderSeller($orderSeller->refresh());
        });
    }

    /**
     * @return array{refund: Refund, created: bool}
     */
    public function refundByAdmin(
        User $admin,
        OrderSeller $orderSeller,
        ?int $amountCents,
        string $reason,
        string $idempotencyKey,
    ): array {
        return DB::transaction(function () use ($admin, $orderSeller, $amountCents, $reason, $idempotencyKey): array {
            $order = Order::query()->lockForUpdate()->findOrFail($orderSeller->order_id);
            $orderSeller = OrderSeller::query()->lockForUpdate()->findOrFail($orderSeller->id);

            $replay = $this->refundService->findByIdempotencyKey($orderSeller, $idempotencyKey);
            if ($replay !== null) {
                if ($amountCents !== null && $replay->amount_cents !== $amountCents) {
                    throw new ConflictHttpException(
                        'A chave de idempotência já foi usada com outro valor de reembolso.',
                    );
                }

                return ['refund' => $replay, 'created' => false];
            }

            $payment = $this->refundService->paidPaymentFor($orderSeller);
            $restock = in_array($orderSeller->status, [
                OrderSellerStatus::PAID,
                OrderSellerStatus::PROCESSING,
            ], true);

            $refund = $this->refundService->issue(
                $orderSeller,
                $payment,
                $amountCents ?? $this->refundService->remainingCents($orderSeller),
                $admin,
                $reason,
                $idempotencyKey,
                restockOnFullRefund: $restock,
            );

            $fullyRefunded = $this->refundService->remainingCents($orderSeller) === 0;

            if (
                $fullyRefunded
                && $this->stateMachine->canTransitionOrderSeller($orderSeller->status, OrderSellerStatus::REFUNDED)
            ) {
                $this->transitionOrderSeller($orderSeller, OrderSellerStatus::REFUNDED, $admin, $reason);
            }

            $this->syncOrderWithSellers($order, $admin, $reason);

            $this->audit($admin, $order, 'order_seller.refunded_by_admin', [
                'order_seller_id' => $orderSeller->id,
                'refund_id' => $refund->id,
                'amount_cents' => $refund->amount_cents,
                'fully_refunded' => $fullyRefunded,
                'restocked' => $refund->restocked,
            ]);

            return ['refund' => $refund, 'created' => true];
        });
    }

    /**
     * Caller must hold a lock on the order row inside a transaction.
     *
     * @return EloquentCollection<int, OrderSeller> sub-orders moved to paid
     */
    public function markPaid(Order $order, string $reason): EloquentCollection
    {
        $sellers = OrderSeller::query()
            ->where('order_id', $order->id)
            ->where('status', '!=', OrderSellerStatus::CANCELLED)
            ->lockForUpdate()
            ->get();

        foreach ($sellers as $seller) {
            $this->transitionOrderSeller($seller, OrderSellerStatus::PAID, null, $reason);
        }

        $this->transitionOrder($order, OrderStatus::PAID, null, $reason);
        $this->commitOrderReservations($order);

        return $sellers;
    }

    /**
     * Sum of active sub-orders; cancelled sellers are not charged.
     */
    public function payableAmountCents(Order $order): int
    {
        return (int) OrderSeller::query()
            ->where('order_id', $order->id)
            ->where('status', '!=', OrderSellerStatus::CANCELLED)
            ->sum('total_cents');
    }

    public function transitionOrder(
        Order $order,
        OrderStatus $to,
        ?User $actor,
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
        ?User $actor,
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

    /**
     * Closes the parent order once no sub-order is still active:
     * all cancelled → cancelled; cancelled/refunded mix → refunded.
     */
    private function syncOrderWithSellers(Order $order, User $actor, string $reason): void
    {
        $statuses = OrderSeller::query()
            ->where('order_id', $order->id)
            ->get(['status'])
            ->pluck('status');

        $allCancelled = $statuses->every(
            static fn (OrderSellerStatus $status): bool => $status === OrderSellerStatus::CANCELLED,
        );
        $allClosed = $statuses->every(
            static fn (OrderSellerStatus $status): bool => in_array($status, [
                OrderSellerStatus::CANCELLED,
                OrderSellerStatus::REFUNDED,
            ], true),
        );

        if ($allCancelled && $order->status !== OrderStatus::CANCELLED) {
            $this->transitionOrder($order, OrderStatus::CANCELLED, $actor, $reason);
            $this->releaseOrderReservations($order);

            return;
        }

        if (
            $allClosed
            && ! $allCancelled
            && $this->stateMachine->canTransitionOrder($order->status, OrderStatus::REFUNDED)
        ) {
            $this->transitionOrder($order, OrderStatus::REFUNDED, $actor, $reason);
        }
    }

    private function commitOrderReservations(Order $order): void
    {
        $reservations = InventoryReservation::query()
            ->where('order_id', $order->id)
            ->where('status', ReservationStatus::ACTIVE)
            ->lockForUpdate()
            ->get();

        foreach ($reservations as $reservation) {
            $this->inventoryService->commit($reservation);
        }
    }

    private function cancelPendingPayments(Order $order): void
    {
        Payment::query()
            ->where('order_id', $order->id)
            ->where('status', PaymentStatus::PENDING)
            ->lockForUpdate()
            ->get()
            ->each(static fn (Payment $payment): bool => $payment->update([
                'status' => PaymentStatus::CANCELLED,
                'cancelled_at' => now(),
            ]));
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
        ?User $actor,
        ?string $reason = null,
    ): void {
        OrderStatusHistory::query()->create([
            'statusable_id' => $statusable->id,
            'statusable_type' => $statusable->getMorphClass(),
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_id' => $actor?->id,
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
