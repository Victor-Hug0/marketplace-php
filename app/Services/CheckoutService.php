<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\ShippingOption;
use App\Enums\OfferStatus;
use App\Enums\OrderSellerStatus;
use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Enums\StoreStatus;
use App\Models\Address;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSeller;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

final class CheckoutService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
        private readonly ShippingQuoteService $shippingQuoteService,
    ) {}

    /**
     * @return array{order: Order, created: bool}
     */
    public function checkout(
        User $user,
        string $addressId,
        string $idempotencyKey,
        ?string $shippingServiceCode = null,
    ): array {
        $existing = $this->findByIdempotencyKey($user, $idempotencyKey);
        if ($existing !== null) {
            return ['order' => $existing, 'created' => false];
        }

        $shippingServiceCode ??= (string) config('shipping.default_service_code');

        try {
            return DB::transaction(function () use ($user, $addressId, $idempotencyKey, $shippingServiceCode): array {
                $replay = $this->findByIdempotencyKey($user, $idempotencyKey);
                if ($replay !== null) {
                    return ['order' => $replay, 'created' => false];
                }

                $cart = Cart::query()
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->first();

                if ($cart === null) {
                    throw new ConflictHttpException('Carrinho vazio. Adicione itens antes do checkout.');
                }

                $items = CartItem::query()
                    ->where('cart_id', $cart->id)
                    ->with(['offer.store', 'offer.product'])
                    ->orderBy('offer_id')
                    ->lockForUpdate()
                    ->get();

                if ($items->isEmpty()) {
                    throw new ConflictHttpException('Carrinho vazio. Adicione itens antes do checkout.');
                }

                $address = Address::query()
                    ->where('user_id', $user->id)
                    ->whereKey($addressId)
                    ->first();

                if ($address === null) {
                    throw new NotFoundHttpException('Endereço não encontrado.');
                }

                $prepared = $this->prepareLineItems($items);
                $orderSubtotalCents = $prepared->sum(
                    static fn (array $line): int => $line['line_total_cents'],
                );

                /** @var Collection<string, Collection<int, array<string, mixed>>> $grouped */
                $grouped = $prepared->groupBy(
                    static fn (array $line): string => (string) $line['store_id'],
                );

                $shippingBySeller = $grouped->map(
                    fn (Collection $storeLines): ShippingOption => $this->selectShipping(
                        $storeLines,
                        $address->cep,
                        $shippingServiceCode,
                    ),
                );
                $quotedAt = now();

                $shippingCents = $shippingBySeller->sum(
                    static fn (ShippingOption $option): int => $option->priceCents,
                );
                $orderTotalCents = $orderSubtotalCents + $shippingCents;

                $order = Order::query()->create([
                    'buyer_id' => $user->id,
                    'status' => OrderStatus::PENDING_PAYMENT,
                    'currency' => 'BRL',
                    'subtotal_cents' => $orderSubtotalCents,
                    'shipping_cents' => $shippingCents,
                    'total_cents' => $orderTotalCents,
                    'idempotency_key' => $idempotencyKey,
                    'payment_due_at' => now()->addMinutes((int) config('orders.unpaid_ttl_minutes')),
                    ...Order::snapshotFromAddress($address),
                ]);

                $this->recordStatusHistory(
                    $order,
                    null,
                    OrderStatus::PENDING_PAYMENT->value,
                    $user,
                    'Pedido criado no checkout.',
                );

                foreach ($grouped as $storeId => $storeLines) {
                    $sellerSubtotalCents = $storeLines->sum(
                        static fn (array $line): int => $line['line_total_cents'],
                    );
                    $shipping = $shippingBySeller->get($storeId);
                    $sellerTotalCents = $sellerSubtotalCents + $shipping->priceCents;

                    $orderSeller = OrderSeller::query()->create([
                        'order_id' => $order->id,
                        'store_id' => $storeId,
                        'status' => OrderSellerStatus::PENDING_PAYMENT,
                        'subtotal_cents' => $sellerSubtotalCents,
                        'shipping_cents' => $shipping->priceCents,
                        'total_cents' => $sellerTotalCents,
                        'shipping_service_code' => $shipping->serviceCode,
                        'shipping_service_name' => $shipping->serviceName,
                        'shipping_eta_days' => $shipping->etaDays,
                        'shipping_quoted_at' => $quotedAt,
                    ]);

                    $this->recordStatusHistory(
                        $orderSeller,
                        null,
                        OrderSellerStatus::PENDING_PAYMENT->value,
                        $user,
                        'Subpedido criado no checkout.',
                    );

                    foreach ($storeLines as $line) {
                        OrderItem::query()->create([
                            'order_seller_id' => $orderSeller->id,
                            'offer_id' => $line['offer']->id,
                            'product_id' => $line['product_id'],
                            'store_id' => $line['store_id'],
                            'product_name' => $line['product_name'],
                            'product_slug' => $line['product_slug'],
                            'unit_price_cents' => $line['unit_price_cents'],
                            'quantity' => $line['quantity'],
                        ]);

                        $reservation = $this->inventoryService->reserve(
                            $line['offer'],
                            $user,
                            $line['quantity'],
                        );

                        $reservation->update(['order_id' => $order->id]);
                    }
                }

                $cart->items()->delete();

                $this->audit($user, $order, 'order.checkout.created', [
                    'sellers_count' => $grouped->count(),
                    'items_count' => $prepared->sum('quantity'),
                    'shipping_service_code' => $shippingServiceCode,
                    'shipping_cents' => $shippingCents,
                    'total_cents' => $orderTotalCents,
                ]);

                return [
                    'order' => $this->loadOrder($order->refresh()),
                    'created' => true,
                ];
            });
        } catch (QueryException $exception) {
            if ($this->isUniqueIdempotencyViolation($exception)) {
                $order = $this->findByIdempotencyKey($user, $idempotencyKey);
                if ($order !== null) {
                    return ['order' => $order, 'created' => false];
                }
            }

            throw $exception;
        }
    }

    private function findByIdempotencyKey(User $user, string $idempotencyKey): ?Order
    {
        $order = Order::query()
            ->where('buyer_id', $user->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        return $order === null ? null : $this->loadOrder($order);
    }

    /**
     * @param  Collection<int, CartItem>  $items
     * @return Collection<int, array<string, mixed>>
     */
    private function prepareLineItems(Collection $items): Collection
    {
        return $items->map(function (CartItem $item): array {
            $offer = $this->lockedPurchasableOffer($item->offer_id);

            if ($offer->availableStock() < $item->quantity) {
                throw new ConflictHttpException(
                    'Estoque insuficiente para concluir o checkout.',
                );
            }

            $unitPriceCents = $this->priceToCents($offer->currentPrice());

            return [
                'offer' => $offer,
                'store_id' => $offer->store_id,
                'product_id' => $offer->product_id,
                'product_name' => $offer->product->name,
                'product_slug' => $offer->product->slug,
                'unit_price_cents' => $unitPriceCents,
                'quantity' => $item->quantity,
                'line_total_cents' => $unitPriceCents * $item->quantity,
            ];
        })->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $storeLines
     */
    private function selectShipping(
        Collection $storeLines,
        string $destinationCep,
        string $serviceCode,
    ): ShippingOption {
        $store = $storeLines->first()['offer']->store;

        $quote = $this->shippingQuoteService->quoteStore(
            $store,
            $storeLines->map(static fn (array $line): array => [
                'product' => $line['offer']->product,
                'quantity' => $line['quantity'],
            ]),
            $destinationCep,
        );

        $option = $quote->option($serviceCode);

        if ($option === null) {
            throw new ConflictHttpException(
                "A modalidade de frete '{$serviceCode}' não está disponível para a loja {$store->store_name}.",
            );
        }

        return $option;
    }

    private function lockedPurchasableOffer(string $offerId): Offer
    {
        $offer = Offer::query()
            ->with(['store', 'product'])
            ->lockForUpdate()
            ->find($offerId);

        if ($offer === null) {
            throw new ConflictHttpException('Uma oferta do carrinho não está mais disponível.');
        }

        if ($offer->status !== OfferStatus::ACTIVE) {
            throw new ConflictHttpException('Uma oferta do carrinho não está disponível para compra.');
        }

        if ($offer->store === null || $offer->store->status !== StoreStatus::ACTIVE) {
            throw new ConflictHttpException('A loja de uma oferta do carrinho não está ativa.');
        }

        if ($offer->product === null || $offer->product->status !== ProductStatus::ACTIVE) {
            throw new ConflictHttpException('O produto de uma oferta do carrinho não está ativo.');
        }

        return $offer;
    }

    private function priceToCents(string $price): int
    {
        return (int) round(((float) $price) * 100);
    }

    private function recordStatusHistory(
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

    private function loadOrder(Order $order): Order
    {
        return $order->load([
            'sellers.store:id,store_name,slug,status,user_id',
            'sellers.items',
            'statusHistories',
            'sellers.statusHistories',
        ]);
    }

    private function isUniqueIdempotencyViolation(Throwable $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'idempotency_key')
            || str_contains($message, 'orders_buyer_id_idempotency_key_unique');
    }
}
