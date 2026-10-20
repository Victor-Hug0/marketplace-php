<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderSellerStatus;
use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Events\OrderSellerDelivered;
use App\Events\OrderSellerShipped;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderSeller;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class FulfillmentService
{
    /**
     * Fulfillment steps of the parent order, in order.
     *
     * @var list<OrderStatus>
     */
    private const ORDER_PROGRESSION = [
        OrderStatus::PAID,
        OrderStatus::PROCESSING,
        OrderStatus::SHIPPED,
        OrderStatus::DELIVERED,
    ];

    public function __construct(
        private readonly OrderService $orderService,
    ) {}

    public function startProcessing(User $seller, OrderSeller $orderSeller): OrderSeller
    {
        return DB::transaction(function () use ($seller, $orderSeller): OrderSeller {
            [$order, $orderSeller] = $this->lockForSeller($seller, $orderSeller);

            $this->orderService->transitionOrderSeller(
                $orderSeller,
                OrderSellerStatus::PROCESSING,
                $seller,
                'Pedido em separação.',
            );

            $this->advanceOrder($order, $seller, 'Pedido em separação.');
            $this->audit($seller, $orderSeller, 'order_seller.processing_started');

            return $this->orderService->loadOrderSeller($orderSeller->refresh());
        });
    }

    public function ship(User $seller, OrderSeller $orderSeller): OrderSeller
    {
        return DB::transaction(function () use ($seller, $orderSeller): OrderSeller {
            [$order, $orderSeller] = $this->lockForSeller($seller, $orderSeller);

            if ($orderSeller->status === OrderSellerStatus::PAID) {
                $this->orderService->transitionOrderSeller(
                    $orderSeller,
                    OrderSellerStatus::PROCESSING,
                    $seller,
                    'Separação iniciada no envio.',
                );
            }

            $this->orderService->transitionOrderSeller(
                $orderSeller,
                OrderSellerStatus::SHIPPED,
                $seller,
                'Pedido enviado.',
            );

            $shippedAt = now();

            $shipment = Shipment::query()->create([
                'order_seller_id' => $orderSeller->id,
                'tracking_code' => $this->newTrackingCode(),
                'carrier' => 'simulated',
                'service_code' => $orderSeller->shipping_service_code,
                'status' => ShipmentStatus::IN_TRANSIT,
                'shipped_at' => $shippedAt,
                'estimated_delivery_at' => $orderSeller->shipping_eta_days === null
                    ? null
                    : $shippedAt->copy()->addDays($orderSeller->shipping_eta_days),
            ]);

            $this->advanceOrder($order, $seller, 'Pedido enviado.');
            event(new OrderSellerShipped($orderSeller));
            $this->audit($seller, $orderSeller, 'order_seller.shipped', [
                'shipment_id' => $shipment->id,
                'tracking_code' => $shipment->tracking_code,
            ]);

            return $this->orderService->loadOrderSeller($orderSeller->refresh());
        });
    }

    /**
     * Seller of the sub-order or an admin confirms the delivery.
     */
    public function markDelivered(User $actor, OrderSeller $orderSeller): OrderSeller
    {
        return DB::transaction(function () use ($actor, $orderSeller): OrderSeller {
            $order = Order::query()->lockForUpdate()->findOrFail($orderSeller->order_id);
            $orderSeller = OrderSeller::query()->lockForUpdate()->findOrFail($orderSeller->id);

            $this->orderService->transitionOrderSeller(
                $orderSeller,
                OrderSellerStatus::DELIVERED,
                $actor,
                'Pedido entregue.',
            );

            $shipment = Shipment::query()
                ->where('order_seller_id', $orderSeller->id)
                ->lockForUpdate()
                ->first();

            if ($shipment === null) {
                throw new ConflictHttpException('O subpedido não possui envio registrado.');
            }

            $shipment->update([
                'status' => ShipmentStatus::DELIVERED,
                'delivered_at' => now(),
            ]);

            $this->advanceOrder($order, $actor, 'Pedido entregue.');
            event(new OrderSellerDelivered($orderSeller));
            $this->audit($actor, $orderSeller, 'order_seller.delivered', [
                'shipment_id' => $shipment->id,
            ]);

            return $this->orderService->loadOrderSeller($orderSeller->refresh());
        });
    }

    /**
     * @return array{Order, OrderSeller}
     */
    private function lockForSeller(User $seller, OrderSeller $orderSeller): array
    {
        $order = Order::query()->lockForUpdate()->findOrFail($orderSeller->order_id);

        $orderSeller = OrderSeller::query()
            ->with('store')
            ->lockForUpdate()
            ->findOrFail($orderSeller->id);

        if ($orderSeller->store?->user_id !== $seller->id) {
            throw new ConflictHttpException('Subpedido não pertence à loja do vendedor.');
        }

        return [$order, $orderSeller];
    }

    /**
     * Moves the parent order to the least advanced step among its active
     * sub-orders, so it only reads "shipped" once every seller has shipped.
     * Cancelled and refunded sub-orders do not hold the order back.
     */
    private function advanceOrder(Order $order, User $actor, string $reason): void
    {
        $activeStatuses = OrderSeller::query()
            ->where('order_id', $order->id)
            ->whereNotIn('status', [OrderSellerStatus::CANCELLED, OrderSellerStatus::REFUNDED])
            ->pluck('status');

        if ($activeStatuses->isEmpty()) {
            return;
        }

        $target = match (true) {
            $activeStatuses->every(
                static fn (OrderSellerStatus $status): bool => $status === OrderSellerStatus::DELIVERED,
            ) => OrderStatus::DELIVERED,
            $activeStatuses->every(
                static fn (OrderSellerStatus $status): bool => in_array($status, [
                    OrderSellerStatus::SHIPPED,
                    OrderSellerStatus::DELIVERED,
                ], true),
            ) => OrderStatus::SHIPPED,
            default => OrderStatus::PROCESSING,
        };

        $current = array_search($order->status, self::ORDER_PROGRESSION, true);
        $goal = array_search($target, self::ORDER_PROGRESSION, true);

        if ($current === false) {
            return;
        }

        for ($step = $current + 1; $step <= $goal; $step++) {
            $this->orderService->transitionOrder($order, self::ORDER_PROGRESSION[$step], $actor, $reason);
        }
    }

    private function newTrackingCode(): string
    {
        do {
            $code = 'SM'.str_pad((string) random_int(0, 999_999_999), 9, '0', STR_PAD_LEFT).'BR';
        } while (Shipment::query()->where('tracking_code', $code)->exists());

        return $code;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function audit(User $actor, OrderSeller $orderSeller, string $action, array $payload = []): void
    {
        AuditLog::query()->create([
            'action' => $action,
            'actor_id' => $actor->id,
            'actor_type' => $actor->getMorphClass(),
            'auditable_id' => $orderSeller->id,
            'auditable_type' => $orderSeller->getMorphClass(),
            'payload' => $payload,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
        ]);
    }
}
