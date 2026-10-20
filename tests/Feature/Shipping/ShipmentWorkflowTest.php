<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Enums\OrderSellerStatus;
use App\Enums\OrderStatus;
use App\Enums\RoleName;
use App\Enums\ShipmentStatus;
use App\Models\Address;
use App\Models\Order;
use App\Models\OrderSeller;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsPaidOrders;
use Tests\Concerns\ConfiguresShipping;
use Tests\TestCase;

final class ShipmentWorkflowTest extends TestCase
{
    use BuildsPaidOrders;
    use ConfiguresShipping;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureMockPayments();
        $this->useFreeShipping();
    }

    public function test_seller_ships_paid_sub_order_with_tracking_code(): void
    {
        $offer = $this->purchasableOffer();
        $order = $this->paidOrder($this->userWithRole(RoleName::BUYER), [$offer->id => 1]);
        $subOrder = $this->subOrderOf($order, $offer);
        $seller = $this->sellerOf($offer->store);

        $response = $this->api($this->accessToken($seller))
            ->postJson("/api/v1/seller/order-sellers/{$subOrder->id}/shipments")
            ->assertCreated()
            ->assertJsonPath('data.status', OrderSellerStatus::SHIPPED->value)
            ->assertJsonPath('data.shipment.status', ShipmentStatus::IN_TRANSIT->value)
            ->assertJsonPath('data.shipment.carrier', 'simulated')
            ->assertJsonPath('data.shipment.service_code', 'simulated_standard');

        $this->assertMatchesRegularExpression('/^SM\d{9}BR$/', $response->json('data.shipment.tracking_code'));

        $shipment = Shipment::query()->where('order_seller_id', $subOrder->id)->firstOrFail();
        $this->assertSame(7, (int) $shipment->shipped_at->diffInDays($shipment->estimated_delivery_at));

        $this->assertSame(
            [OrderSellerStatus::PROCESSING->value, OrderSellerStatus::SHIPPED->value],
            $this->historyOf($subOrder, ['processing', 'shipped']),
        );
        $this->assertSame(OrderStatus::SHIPPED, $order->fresh()->status);
    }

    public function test_processing_then_ship_moves_parent_order_step_by_step(): void
    {
        $offer = $this->purchasableOffer();
        $order = $this->paidOrder($this->userWithRole(RoleName::BUYER), [$offer->id => 1]);
        $subOrder = $this->subOrderOf($order, $offer);
        $token = $this->accessToken($this->sellerOf($offer->store));

        $this->api($token)
            ->postJson("/api/v1/seller/order-sellers/{$subOrder->id}/processing")
            ->assertOk()
            ->assertJsonPath('data.status', OrderSellerStatus::PROCESSING->value)
            ->assertJsonPath('data.shipment', null);

        $this->assertSame(OrderStatus::PROCESSING, $order->fresh()->status);

        $this->api($token)
            ->postJson("/api/v1/seller/order-sellers/{$subOrder->id}/shipments")
            ->assertCreated();

        $this->api($token)
            ->postJson("/api/v1/seller/order-sellers/{$subOrder->id}/deliver")
            ->assertOk()
            ->assertJsonPath('data.status', OrderSellerStatus::DELIVERED->value)
            ->assertJsonPath('data.shipment.status', ShipmentStatus::DELIVERED->value);

        $this->assertSame(OrderStatus::DELIVERED, $order->fresh()->status);
        $this->assertNotNull(Shipment::query()->where('order_seller_id', $subOrder->id)->value('delivered_at'));
    }

    public function test_parent_order_follows_the_slowest_seller(): void
    {
        $offerA = $this->purchasableOffer();
        $offerB = $this->purchasableOffer();
        $order = $this->paidOrder($this->userWithRole(RoleName::BUYER), [$offerA->id => 1, $offerB->id => 1]);
        $subA = $this->subOrderOf($order, $offerA);
        $subB = $this->subOrderOf($order, $offerB);
        $tokenA = $this->accessToken($this->sellerOf($offerA->store));
        $tokenB = $this->accessToken($this->sellerOf($offerB->store));
        $adminToken = $this->accessToken($this->userWithRole(RoleName::ADMIN));

        $this->api($tokenA)->postJson("/api/v1/seller/order-sellers/{$subA->id}/shipments")->assertCreated();
        $this->assertSame(OrderStatus::PROCESSING, $order->fresh()->status);

        $this->api($tokenB)->postJson("/api/v1/seller/order-sellers/{$subB->id}/shipments")->assertCreated();
        $this->assertSame(OrderStatus::SHIPPED, $order->fresh()->status);

        $this->api($tokenA)->postJson("/api/v1/seller/order-sellers/{$subA->id}/deliver")->assertOk();
        $this->assertSame(OrderStatus::SHIPPED, $order->fresh()->status);

        $this->api($adminToken)
            ->postJson("/api/v1/admin/order-sellers/{$subB->id}/deliver")
            ->assertOk()
            ->assertJsonPath('data.status', OrderSellerStatus::DELIVERED->value);

        $this->assertSame(OrderStatus::DELIVERED, $order->fresh()->status);
        $this->assertSame(
            [OrderStatus::PAID->value, OrderStatus::PROCESSING->value, OrderStatus::SHIPPED->value, OrderStatus::DELIVERED->value],
            $order->fresh()->statusHistories()->reorder('created_at')->orderBy('id')->pluck('to_status')
                ->filter(static fn (string $status): bool => $status !== OrderStatus::PENDING_PAYMENT->value)
                ->values()
                ->all(),
        );
    }

    public function test_cancelled_sub_order_does_not_hold_back_delivery(): void
    {
        $offerA = $this->purchasableOffer();
        $offerB = $this->purchasableOffer();
        $order = $this->paidOrder($this->userWithRole(RoleName::BUYER), [$offerA->id => 1, $offerB->id => 1]);
        $subA = $this->subOrderOf($order, $offerA);
        $subB = $this->subOrderOf($order, $offerB);
        $tokenA = $this->accessToken($this->sellerOf($offerA->store));

        $this->api($this->accessToken($this->sellerOf($offerB->store)))
            ->postJson("/api/v1/seller/order-sellers/{$subB->id}/cancel", ['reason' => 'Sem estoque'])
            ->assertOk();

        $this->api($tokenA)->postJson("/api/v1/seller/order-sellers/{$subA->id}/shipments")->assertCreated();
        $this->assertSame(OrderStatus::SHIPPED, $order->fresh()->status);

        $this->api($tokenA)->postJson("/api/v1/seller/order-sellers/{$subA->id}/deliver")->assertOk();
        $this->assertSame(OrderStatus::DELIVERED, $order->fresh()->status);
    }

    public function test_invalid_fulfillment_transitions_return_conflict(): void
    {
        $offer = $this->purchasableOffer();
        $buyer = $this->userWithRole(RoleName::BUYER);
        $token = $this->accessToken($this->sellerOf($offer->store));

        $unpaid = $this->unpaidSubOrder($buyer, $offer->id);
        $this->api($token)->postJson("/api/v1/seller/order-sellers/{$unpaid->id}/shipments")->assertConflict();
        $this->api($token)->postJson("/api/v1/seller/order-sellers/{$unpaid->id}/processing")->assertConflict();

        $paid = $this->subOrderOf($this->paidOrder($buyer, [$offer->id => 1]), $offer);
        $this->api($token)->postJson("/api/v1/seller/order-sellers/{$paid->id}/deliver")->assertConflict();

        $this->api($token)->postJson("/api/v1/seller/order-sellers/{$paid->id}/shipments")->assertCreated();
        $this->api($token)->postJson("/api/v1/seller/order-sellers/{$paid->id}/shipments")->assertConflict();
        $this->api($token)
            ->postJson("/api/v1/seller/order-sellers/{$paid->id}/cancel", ['reason' => 'Desisti'])
            ->assertConflict();

        $this->assertSame(1, Shipment::query()->count());
    }

    public function test_only_the_owning_seller_or_admin_can_fulfill(): void
    {
        $offer = $this->purchasableOffer();
        $buyer = $this->userWithRole(RoleName::BUYER);
        $order = $this->paidOrder($buyer, [$offer->id => 1]);
        $subOrder = $this->subOrderOf($order, $offer);
        $otherSeller = $this->sellerOf($this->purchasableOffer()->store);

        $this->api($this->accessToken($otherSeller))
            ->postJson("/api/v1/seller/order-sellers/{$subOrder->id}/shipments")
            ->assertNotFound();

        $buyerToken = $this->accessToken($buyer);
        $this->api($buyerToken)
            ->postJson("/api/v1/seller/order-sellers/{$subOrder->id}/shipments")
            ->assertForbidden();
        $this->api($buyerToken)
            ->postJson("/api/v1/admin/order-sellers/{$subOrder->id}/deliver")
            ->assertForbidden();

        $this->asGuest()
            ->postJson("/api/v1/seller/order-sellers/{$subOrder->id}/shipments")
            ->assertUnauthorized();

        $this->assertSame(OrderSellerStatus::PAID, $subOrder->fresh()->status);
    }

    public function test_buyer_and_seller_see_tracking(): void
    {
        $offer = $this->purchasableOffer();
        $buyer = $this->userWithRole(RoleName::BUYER);
        $order = $this->paidOrder($buyer, [$offer->id => 1]);
        $subOrder = $this->subOrderOf($order, $offer);
        $sellerToken = $this->accessToken($this->sellerOf($offer->store));

        $trackingCode = $this->api($sellerToken)
            ->postJson("/api/v1/seller/order-sellers/{$subOrder->id}/shipments")
            ->assertCreated()
            ->json('data.shipment.tracking_code');

        $this->api($this->accessToken($buyer))
            ->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::SHIPPED->value)
            ->assertJsonPath('data.sellers.0.shipment.tracking_code', $trackingCode);

        $this->api($this->accessToken($buyer))
            ->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonPath('data.0.sellers.0.shipment.tracking_code', $trackingCode);

        $this->api($sellerToken)
            ->getJson("/api/v1/seller/order-sellers/{$subOrder->id}")
            ->assertOk()
            ->assertJsonPath('data.shipment.tracking_code', $trackingCode);
    }

    /**
     * @param  list<string>  $statuses
     * @return list<string>
     */
    private function historyOf(OrderSeller $subOrder, array $statuses): array
    {
        return $subOrder->statusHistories()
            ->reorder('created_at')
            ->orderBy('id')
            ->whereIn('to_status', $statuses)
            ->pluck('to_status')
            ->all();
    }

    private function unpaidSubOrder(User $buyer, string $offerId): OrderSeller
    {
        $token = $this->accessToken($buyer);
        $address = Address::factory()->belongingToUser($buyer)->create();

        $this->api($token)
            ->postJson('/api/v1/cart/items', ['offer_id' => $offerId, 'quantity' => 1])
            ->assertCreated();

        $orderId = $this->api($token)
            ->postJson('/api/v1/checkout', ['address_id' => $address->id], [
                'Idempotency-Key' => 'unpaid-'.Str::uuid(),
            ])
            ->assertCreated()
            ->json('data.id');

        return Order::query()->findOrFail($orderId)->sellers()->firstOrFail();
    }
}
