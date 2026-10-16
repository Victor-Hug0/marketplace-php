<?php

declare(strict_types=1);

namespace Tests\Feature\Checkout;

use App\Enums\OfferStatus;
use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Enums\ReservationStatus;
use App\Enums\RoleName;
use App\Enums\StoreStatus;
use App\Models\Address;
use App\Models\InventoryReservation;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CheckoutWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_checkout(): void
    {
        $this->postJson('/api/v1/checkout', [
            'address_id' => (string) Str::uuid(),
        ], [
            'Idempotency-Key' => 'checkout-key-001',
        ])->assertUnauthorized();
    }

    public function test_checkout_requires_idempotency_key(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $address = Address::factory()->belongingToUser($buyer)->create();

        $this->withToken($this->accessToken($buyer))
            ->postJson('/api/v1/checkout', [
                'address_id' => $address->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['Idempotency-Key']);
    }

    public function test_checkout_creates_multi_seller_order_and_clears_cart(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $token = $this->accessToken($buyer);
        $address = Address::factory()->belongingToUser($buyer)->create([
            'recipient_name' => 'Maria Compradora',
            'city' => 'São Paulo',
            'state' => 'SP',
        ]);

        $offerA = $this->purchasableOffer(price: '50.00', stock: 10);
        $offerB = $this->purchasableOffer(price: '30.00', stock: 5);

        $this->withToken($token)
            ->postJson('/api/v1/cart/items', [
                'offer_id' => $offerA->id,
                'quantity' => 2,
            ])
            ->assertCreated();

        $this->withToken($token)
            ->postJson('/api/v1/cart/items', [
                'offer_id' => $offerB->id,
                'quantity' => 1,
            ])
            ->assertCreated();

        $response = $this->withToken($token)
            ->postJson('/api/v1/checkout', [
                'address_id' => $address->id,
            ], [
                'Idempotency-Key' => 'multi-seller-checkout-1',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', OrderStatus::PENDING_PAYMENT->value)
            ->assertJsonPath('data.subtotal_cents', 13000)
            ->assertJsonPath('data.shipping_cents', 0)
            ->assertJsonPath('data.total_cents', 13000)
            ->assertJsonPath('data.address_snapshot.recipient_name', 'Maria Compradora')
            ->assertJsonPath('data.address_snapshot.city', 'São Paulo')
            ->assertJsonCount(2, 'data.sellers');

        $orderId = $response->json('data.id');

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_sellers', 2);
        $this->assertDatabaseCount('order_items', 2);
        $this->assertDatabaseCount('cart_items', 0);
        $this->assertDatabaseCount('inventory_reservations', 2);

        $this->assertSame(2, InventoryReservation::query()
            ->where('order_id', $orderId)
            ->where('status', ReservationStatus::ACTIVE)
            ->count());

        $offerA->refresh();
        $offerB->refresh();
        $this->assertSame(2, $offerA->reserved_stock);
        $this->assertSame(1, $offerB->reserved_stock);

        $this->withToken($token)
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonCount(0, 'data.items');
    }

    public function test_checkout_snapshots_unit_price_even_if_offer_changes_later(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $token = $this->accessToken($buyer);
        $address = Address::factory()->belongingToUser($buyer)->create();
        $offer = $this->purchasableOffer(price: '100.00', stock: 5);

        $this->withToken($token)
            ->postJson('/api/v1/cart/items', [
                'offer_id' => $offer->id,
                'quantity' => 1,
            ])
            ->assertCreated();

        $orderId = $this->withToken($token)
            ->postJson('/api/v1/checkout', [
                'address_id' => $address->id,
            ], [
                'Idempotency-Key' => 'price-snapshot-key-1',
            ])
            ->assertCreated()
            ->assertJsonPath('data.sellers.0.items.0.unit_price_cents', 10000)
            ->json('data.id');

        $offer->update(['price' => '999.00']);

        $this->assertDatabaseHas('order_items', [
            'unit_price_cents' => 10000,
        ]);

        $order = Order::query()->with('sellers.items')->findOrFail($orderId);
        $this->assertSame(10000, $order->sellers->first()->items->first()->unit_price_cents);
    }

    public function test_checkout_is_idempotent_for_same_key(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $token = $this->accessToken($buyer);
        $address = Address::factory()->belongingToUser($buyer)->create();
        $offer = $this->purchasableOffer(price: '40.00', stock: 5);

        $this->withToken($token)
            ->postJson('/api/v1/cart/items', [
                'offer_id' => $offer->id,
                'quantity' => 1,
            ])
            ->assertCreated();

        $first = $this->withToken($token)
            ->postJson('/api/v1/checkout', [
                'address_id' => $address->id,
            ], [
                'Idempotency-Key' => 'same-idempotency-key-99',
            ])
            ->assertCreated();

        $second = $this->withToken($token)
            ->postJson('/api/v1/checkout', [
                'address_id' => $address->id,
            ], [
                'Idempotency-Key' => 'same-idempotency-key-99',
            ])
            ->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('inventory_reservations', 1);
        $this->assertSame(1, $offer->fresh()->reserved_stock);
    }

    public function test_checkout_fails_when_stock_is_insufficient(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $token = $this->accessToken($buyer);
        $address = Address::factory()->belongingToUser($buyer)->create();
        $offer = $this->purchasableOffer(price: '10.00', stock: 2, reserved: 0);

        $this->withToken($token)
            ->postJson('/api/v1/cart/items', [
                'offer_id' => $offer->id,
                'quantity' => 2,
            ])
            ->assertCreated();

        $offer->update(['reserved_stock' => 2]);

        $this->withToken($token)
            ->postJson('/api/v1/checkout', [
                'address_id' => $address->id,
            ], [
                'Idempotency-Key' => 'insufficient-stock-key-1',
            ])
            ->assertConflict()
            ->assertJsonPath('message', 'Estoque insuficiente para concluir o checkout.');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_checkout_rejects_empty_cart(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $address = Address::factory()->belongingToUser($buyer)->create();

        $this->withToken($this->accessToken($buyer))
            ->postJson('/api/v1/checkout', [
                'address_id' => $address->id,
            ], [
                'Idempotency-Key' => 'empty-cart-key-1234',
            ])
            ->assertConflict()
            ->assertJsonPath('message', 'Carrinho vazio. Adicione itens antes do checkout.');
    }

    public function test_checkout_rejects_foreign_address(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $other = $this->userWithRole(RoleName::BUYER);
        $foreignAddress = Address::factory()->belongingToUser($other)->create();
        $offer = $this->purchasableOffer(stock: 3);
        $token = $this->accessToken($buyer);

        $this->withToken($token)
            ->postJson('/api/v1/cart/items', [
                'offer_id' => $offer->id,
                'quantity' => 1,
            ])
            ->assertCreated();

        $this->withToken($token)
            ->postJson('/api/v1/checkout', [
                'address_id' => $foreignAddress->id,
            ], [
                'Idempotency-Key' => 'foreign-address-key-1',
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_checkout_rejects_inactive_offer_in_cart(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $token = $this->accessToken($buyer);
        $address = Address::factory()->belongingToUser($buyer)->create();
        $offer = $this->purchasableOffer(stock: 3);

        $this->withToken($token)
            ->postJson('/api/v1/cart/items', [
                'offer_id' => $offer->id,
                'quantity' => 1,
            ])
            ->assertCreated();

        $offer->update(['status' => OfferStatus::PAUSED]);

        $this->withToken($token)
            ->postJson('/api/v1/checkout', [
                'address_id' => $address->id,
            ], [
                'Idempotency-Key' => 'paused-offer-checkout-1',
            ])
            ->assertConflict();

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_checkout_rejects_suspended_store(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $token = $this->accessToken($buyer);
        $address = Address::factory()->belongingToUser($buyer)->create();
        $offer = $this->purchasableOffer(stock: 3);

        $this->withToken($token)
            ->postJson('/api/v1/cart/items', [
                'offer_id' => $offer->id,
                'quantity' => 1,
            ])
            ->assertCreated();

        $offer->store->update(['status' => StoreStatus::SUSPENDED]);

        $this->withToken($token)
            ->postJson('/api/v1/checkout', [
                'address_id' => $address->id,
            ], [
                'Idempotency-Key' => 'suspended-store-checkout-1',
            ])
            ->assertConflict();

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_checkout_rejects_inactive_product(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $token = $this->accessToken($buyer);
        $address = Address::factory()->belongingToUser($buyer)->create();
        $offer = $this->purchasableOffer(stock: 3);

        $this->withToken($token)
            ->postJson('/api/v1/cart/items', [
                'offer_id' => $offer->id,
                'quantity' => 1,
            ])
            ->assertCreated();

        $offer->product->update(['status' => ProductStatus::PAUSED]);

        $this->withToken($token)
            ->postJson('/api/v1/checkout', [
                'address_id' => $address->id,
            ], [
                'Idempotency-Key' => 'inactive-product-checkout-1',
            ])
            ->assertConflict();

        $this->assertDatabaseCount('orders', 0);
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

        return $user;
    }

    private function accessToken(User $user): string
    {
        return $user->createToken(
            'access_token',
            ['access'],
            now()->addHour(),
        )->plainTextToken;
    }
}
