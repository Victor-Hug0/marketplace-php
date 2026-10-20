<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Enums\OrderSellerStatus;
use App\Enums\OrderStatus;
use App\Models\Address;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSeller;
use App\Models\OrderStatusHistory;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrderSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_persists_multi_seller_structure_with_price_snapshots(): void
    {
        $buyer = User::factory()->create();
        $address = Address::factory()->belongingToUser($buyer)->create();

        $storeA = Store::factory()->active()->create();
        $storeB = Store::factory()->active()->create();

        $offerA = Offer::factory()->belongingToStore($storeA)->priced('50.00')->create();
        $offerB = Offer::factory()->belongingToStore($storeB)->priced('30.00')->create();

        $order = Order::factory()
            ->forBuyer($buyer)
            ->withAddress($address)
            ->withTotals(13000, 1500)
            ->create([
                'idempotency_key' => 'checkout-key-1',
            ]);

        $orderSellerA = OrderSeller::factory()
            ->forOrder($order)
            ->forStore($storeA)
            ->withTotals(10000, 800)
            ->create();

        $orderSellerB = OrderSeller::factory()
            ->forOrder($order)
            ->forStore($storeB)
            ->withTotals(3000, 700)
            ->create();

        OrderItem::factory()
            ->forOrderSeller($orderSellerA)
            ->fromOffer($offerA, 5000)
            ->withQuantity(2)
            ->create();

        OrderItem::factory()
            ->forOrderSeller($orderSellerB)
            ->fromOffer($offerB, 3000)
            ->withQuantity(1)
            ->create();

        OrderStatusHistory::factory()
            ->forStatusable($order)
            ->transition(null, OrderStatus::PENDING_PAYMENT->value)
            ->byActor($buyer)
            ->create();

        OrderStatusHistory::factory()
            ->forStatusable($orderSellerA)
            ->transition(null, OrderSellerStatus::PENDING_PAYMENT->value)
            ->create();

        $order->refresh()->load(['sellers.items', 'statusHistories']);

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->status);
        $this->assertSame(13000, $order->subtotal_cents);
        $this->assertSame(1500, $order->shipping_cents);
        $this->assertSame(14500, $order->total_cents);
        $this->assertSame($address->recipient_name, $order->shipping_recipient_name);
        $this->assertSame($address->cep, $order->shipping_cep);
        $this->assertCount(2, $order->sellers);
        $this->assertCount(2, $order->items);
        $this->assertCount(1, $order->statusHistories);

        $itemA = $orderSellerA->items()->firstOrFail();
        $this->assertSame(5000, $itemA->unit_price_cents);
        $this->assertSame(2, $itemA->quantity);
        $this->assertSame(10000, $itemA->lineTotalCents());
        $this->assertSame($offerA->product->name, $itemA->product_name);

        $offerA->update(['price_cents' => 9900]);
        $itemA->refresh();
        $this->assertSame(5000, $itemA->unit_price_cents);
    }

    public function test_idempotency_key_is_unique_per_buyer(): void
    {
        $buyer = User::factory()->create();
        $key = (string) Str::uuid();

        Order::factory()->forBuyer($buyer)->create([
            'idempotency_key' => $key,
        ]);

        $this->expectException(QueryException::class);

        Order::factory()->forBuyer($buyer)->create([
            'idempotency_key' => $key,
        ]);
    }

    public function test_same_idempotency_key_allowed_for_different_buyers(): void
    {
        $key = 'shared-key';

        Order::factory()->forBuyer(User::factory()->create())->create([
            'idempotency_key' => $key,
        ]);

        Order::factory()->forBuyer(User::factory()->create())->create([
            'idempotency_key' => $key,
        ]);

        $this->assertDatabaseCount('orders', 2);
    }

    public function test_order_seller_is_unique_per_store_within_order(): void
    {
        $order = Order::factory()->create();
        $store = Store::factory()->active()->create();

        OrderSeller::factory()->forOrder($order)->forStore($store)->create();

        $this->expectException(QueryException::class);

        OrderSeller::factory()->forOrder($order)->forStore($store)->create();
    }

    public function test_order_item_keeps_offer_null_when_offer_is_deleted(): void
    {
        $orderSeller = OrderSeller::factory()->create();
        $offer = Offer::factory()->create();

        $item = OrderItem::factory()
            ->forOrderSeller($orderSeller)
            ->fromOffer($offer, 2500)
            ->withQuantity(1)
            ->create();

        $offer->forceDelete();

        $item->refresh();

        $this->assertNull($item->offer_id);
        $this->assertSame(2500, $item->unit_price_cents);
        $this->assertNotNull($item->product_id);
    }

    public function test_buyer_orders_relation_and_store_order_sellers_relation(): void
    {
        $buyer = User::factory()->create();
        $store = Store::factory()->active()->create();

        $order = Order::factory()->forBuyer($buyer)->create();
        OrderSeller::factory()->forOrder($order)->forStore($store)->create();

        $this->assertTrue($buyer->orders()->whereKey($order->id)->exists());
        $this->assertTrue($store->orderSellers()->where('order_id', $order->id)->exists());
    }

    public function test_enums_expose_terminal_statuses(): void
    {
        $this->assertTrue(OrderStatus::DELIVERED->isTerminal());
        $this->assertTrue(OrderStatus::CANCELLED->isTerminal());
        $this->assertTrue(OrderStatus::REFUNDED->isTerminal());
        $this->assertFalse(OrderStatus::PENDING_PAYMENT->isTerminal());

        $this->assertFalse(OrderSellerStatus::SHIPPED->isTerminal());
        $this->assertTrue(OrderSellerStatus::DELIVERED->isTerminal());
    }
}
