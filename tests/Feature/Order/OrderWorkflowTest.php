<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Enums\OrderSellerStatus;
use App\Enums\OrderStatus;
use App\Enums\ReservationStatus;
use App\Enums\RoleName;
use App\Models\Address;
use App\Models\InventoryReservation;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderSeller;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\OrderStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\ConfiguresShipping;
use Tests\TestCase;

final class OrderWorkflowTest extends TestCase
{
    use ConfiguresShipping;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useFreeShipping();
    }

    public function test_guest_cannot_access_orders(): void
    {
        $this->getJson('/api/v1/orders')->assertUnauthorized();
        $this->getJson('/api/v1/admin/orders')->assertUnauthorized();
        $this->getJson('/api/v1/seller/order-sellers')->assertUnauthorized();
    }

    public function test_buyer_lists_and_shows_own_orders_with_history(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $token = $this->accessToken($buyer);
        $order = $this->checkoutOrder($buyer, $token);

        $this->withToken($token)
            ->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $order->id)
            ->assertJsonPath('data.0.status', OrderStatus::PENDING_PAYMENT->value);

        $this->withToken($token)
            ->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonCount(1, 'data.status_histories')
            ->assertJsonPath('data.status_histories.0.to_status', OrderStatus::PENDING_PAYMENT->value)
            ->assertJsonCount(1, 'data.sellers');
    }

    public function test_buyer_cannot_view_another_buyers_order(): void
    {
        $owner = $this->userWithRole(RoleName::BUYER);
        $intruder = $this->userWithRole(RoleName::BUYER);
        $order = $this->checkoutOrder($owner, $this->accessToken($owner));

        $this->withToken($this->accessToken($intruder))
            ->getJson("/api/v1/orders/{$order->id}")
            ->assertNotFound();

        $this->withToken($this->accessToken($intruder))
            ->postJson("/api/v1/orders/{$order->id}/cancel")
            ->assertNotFound();
    }

    public function test_buyer_cancels_pending_payment_order_and_releases_reservations(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $token = $this->accessToken($buyer);
        $offer = $this->purchasableOffer(stock: 5);
        $order = $this->checkoutOrder($buyer, $token, [$offer->id => 2]);

        $this->assertSame(2, $offer->fresh()->reserved_stock);
        $this->assertDatabaseHas('inventory_reservations', [
            'order_id' => $order->id,
            'status' => ReservationStatus::ACTIVE->value,
        ]);

        $this->withToken($token)
            ->postJson("/api/v1/orders/{$order->id}/cancel", [
                'reason' => 'Desisti da compra',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::CANCELLED->value)
            ->assertJsonPath('data.sellers.0.status', OrderSellerStatus::CANCELLED->value);

        $this->assertSame(0, $offer->fresh()->reserved_stock);
        $this->assertSame(0, InventoryReservation::query()
            ->where('order_id', $order->id)
            ->where('status', ReservationStatus::ACTIVE)
            ->count());
        $this->assertSame(1, InventoryReservation::query()
            ->where('order_id', $order->id)
            ->where('status', ReservationStatus::RELEASED)
            ->count());

        $histories = $order->fresh()->statusHistories;
        $this->assertTrue(
            $histories->contains(
                fn ($history): bool => $history->from_status === OrderStatus::PENDING_PAYMENT->value
                    && $history->to_status === OrderStatus::CANCELLED->value,
            ),
        );
    }

    public function test_buyer_cannot_cancel_paid_order(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $token = $this->accessToken($buyer);
        $order = $this->checkoutOrder($buyer, $token);
        $order->update(['status' => OrderStatus::PAID]);

        $this->withToken($token)
            ->postJson("/api/v1/orders/{$order->id}/cancel")
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'O comprador só pode cancelar pedidos em pending_payment.',
            );
    }

    public function test_seller_lists_and_cancels_own_order_seller(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $buyerToken = $this->accessToken($buyer);

        $offerA = $this->purchasableOffer(price: '50.00', stock: 5);
        $offerB = $this->purchasableOffer(price: '30.00', stock: 5);
        $order = $this->checkoutOrder($buyer, $buyerToken, [
            $offerA->id => 1,
            $offerB->id => 1,
        ]);

        $orderSellerA = OrderSeller::query()
            ->where('order_id', $order->id)
            ->where('store_id', $offerA->store_id)
            ->firstOrFail();

        $sellerA = $offerA->store->owner;
        $sellerA->roles()->syncWithoutDetaching([
            Role::query()->firstOrCreate(['name' => RoleName::SELLER])->id,
        ]);
        $sellerToken = $this->accessToken($sellerA);

        $this->withToken($sellerToken)
            ->getJson('/api/v1/seller/order-sellers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $orderSellerA->id);

        $this->withToken($sellerToken)
            ->getJson("/api/v1/seller/order-sellers/{$orderSellerA->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $orderSellerA->id)
            ->assertJsonPath('data.status_histories.0.to_status', OrderSellerStatus::PENDING_PAYMENT->value);

        $this->withToken($sellerToken)
            ->postJson("/api/v1/seller/order-sellers/{$orderSellerA->id}/cancel", [
                'reason' => 'Sem estoque físico',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', OrderSellerStatus::CANCELLED->value);

        $this->assertSame(0, $offerA->fresh()->reserved_stock);
        $this->assertSame(1, $offerB->fresh()->reserved_stock);
        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->fresh()->status);
    }

    public function test_seller_cancel_last_order_seller_cancels_parent_order(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $buyerToken = $this->accessToken($buyer);
        $offer = $this->purchasableOffer(stock: 3);
        $order = $this->checkoutOrder($buyer, $buyerToken, [$offer->id => 1]);

        $orderSeller = OrderSeller::query()->where('order_id', $order->id)->firstOrFail();
        $seller = $offer->store->owner;
        $seller->roles()->syncWithoutDetaching([
            Role::query()->firstOrCreate(['name' => RoleName::SELLER])->id,
        ]);

        $this->withToken($this->accessToken($seller))
            ->postJson("/api/v1/seller/order-sellers/{$orderSeller->id}/cancel")
            ->assertOk();

        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
        $this->assertSame(0, $offer->fresh()->reserved_stock);
    }

    public function test_seller_cannot_access_another_stores_order_seller(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $offer = $this->purchasableOffer(stock: 3);
        $order = $this->checkoutOrder($buyer, $this->accessToken($buyer), [$offer->id => 1]);
        $orderSeller = OrderSeller::query()->where('order_id', $order->id)->firstOrFail();

        $otherSeller = $this->userWithRole(RoleName::SELLER);
        Store::factory()->active()->create(['user_id' => $otherSeller->id]);

        $this->withToken($this->accessToken($otherSeller))
            ->getJson("/api/v1/seller/order-sellers/{$orderSeller->id}")
            ->assertNotFound();

        $this->withToken($this->accessToken($otherSeller))
            ->postJson("/api/v1/seller/order-sellers/{$orderSeller->id}/cancel")
            ->assertNotFound();
    }

    public function test_seller_cannot_cancel_shipped_order_seller(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $offer = $this->purchasableOffer(stock: 3);
        $order = $this->checkoutOrder($buyer, $this->accessToken($buyer), [$offer->id => 1]);
        $orderSeller = OrderSeller::query()->where('order_id', $order->id)->firstOrFail();
        $orderSeller->update(['status' => OrderSellerStatus::SHIPPED]);

        $seller = $offer->store->owner;
        $seller->roles()->syncWithoutDetaching([
            Role::query()->firstOrCreate(['name' => RoleName::SELLER])->id,
        ]);

        $this->withToken($this->accessToken($seller))
            ->postJson("/api/v1/seller/order-sellers/{$orderSeller->id}/cancel")
            ->assertConflict();
    }

    public function test_admin_lists_and_shows_all_orders(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $order = $this->checkoutOrder($buyer, $this->accessToken($buyer));

        $admin = $this->userWithRole(RoleName::ADMIN);

        $this->withToken($this->accessToken($admin))
            ->getJson('/api/v1/admin/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $order->id);

        $this->withToken($this->accessToken($admin))
            ->getJson("/api/v1/admin/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonCount(1, 'data.status_histories');
    }

    public function test_non_admin_cannot_access_admin_orders(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);

        $this->withToken($this->accessToken($buyer))
            ->getJson('/api/v1/admin/orders')
            ->assertForbidden();
    }

    public function test_order_state_machine_matrix(): void
    {
        $machine = new OrderStateMachine;

        $this->assertTrue($machine->canTransitionOrder(
            OrderStatus::PENDING_PAYMENT,
            OrderStatus::PAID,
        ));
        $this->assertTrue($machine->canTransitionOrder(
            OrderStatus::PENDING_PAYMENT,
            OrderStatus::CANCELLED,
        ));
        $this->assertFalse($machine->canTransitionOrder(
            OrderStatus::PENDING_PAYMENT,
            OrderStatus::SHIPPED,
        ));
        $this->assertTrue($machine->canTransitionOrder(
            OrderStatus::PAID,
            OrderStatus::PROCESSING,
        ));
        $this->assertTrue($machine->canTransitionOrder(
            OrderStatus::PROCESSING,
            OrderStatus::SHIPPED,
        ));
        $this->assertTrue($machine->canTransitionOrder(
            OrderStatus::SHIPPED,
            OrderStatus::DELIVERED,
        ));
        $this->assertFalse($machine->canTransitionOrder(
            OrderStatus::DELIVERED,
            OrderStatus::CANCELLED,
        ));
        $this->assertFalse($machine->canTransitionOrder(
            OrderStatus::CANCELLED,
            OrderStatus::PAID,
        ));

        $this->assertTrue($machine->sellerCanCancel(OrderSellerStatus::PENDING_PAYMENT));
        $this->assertTrue($machine->sellerCanCancel(OrderSellerStatus::PROCESSING));
        $this->assertFalse($machine->sellerCanCancel(OrderSellerStatus::SHIPPED));
        $this->assertFalse($machine->sellerCanCancel(OrderSellerStatus::DELIVERED));
    }

    /**
     * @param  array<string, int>|null  $offers
     */
    private function checkoutOrder(User $buyer, string $token, ?array $offers = null): Order
    {
        $address = Address::factory()->belongingToUser($buyer)->create();

        if ($offers === null) {
            $offer = $this->purchasableOffer(stock: 5);
            $offers = [$offer->id => 1];
        }

        foreach ($offers as $offerId => $quantity) {
            $this->withToken($token)
                ->postJson('/api/v1/cart/items', [
                    'offer_id' => $offerId,
                    'quantity' => $quantity,
                ])
                ->assertCreated();
        }

        $orderId = $this->withToken($token)
            ->postJson('/api/v1/checkout', [
                'address_id' => $address->id,
            ], [
                'Idempotency-Key' => 'order-flow-'.Str::uuid(),
            ])
            ->assertCreated()
            ->json('data.id');

        return Order::query()->findOrFail($orderId);
    }

    private function purchasableOffer(
        string $price = '99.90',
        int $stock = 10,
        int $reserved = 0,
    ): Offer {
        $store = Store::factory()->active()->create();
        $product = Product::factory()->active()->create([
            'store_id' => $store->id,
        ]);

        return Offer::factory()
            ->active()
            ->withStock($stock, $reserved)
            ->priced($price)
            ->create([
                'store_id' => $store->id,
                'product_id' => $product->id,
            ]);
    }

    private function userWithRole(RoleName $role): User
    {
        $user = User::factory()->create();
        $roleModel = Role::query()->firstOrCreate(['name' => $role]);
        $user->roles()->syncWithoutDetaching([$roleModel->id]);

        return $user->fresh();
    }

    private function accessToken(User $user): string
    {
        // Avoid auth user leaking across requests in the same feature test.
        $this->app['auth']->forgetGuards();

        return $user->createToken(
            'access_token',
            ['access'],
            now()->addHour(),
        )->plainTextToken;
    }
}
