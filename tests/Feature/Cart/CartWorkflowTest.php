<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Enums\OfferStatus;
use App\Enums\ProductStatus;
use App\Enums\RoleName;
use App\Enums\StoreStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Offer;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CartWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_cart(): void
    {
        $this->getJson('/api/v1/cart')->assertUnauthorized();
    }

    public function test_authenticated_user_gets_empty_cart(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);

        $this->withToken($this->accessToken($buyer))
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.items_count', 0)
            ->assertJsonPath('data.subtotal', '0.00')
            ->assertJsonCount(0, 'data.items');
    }

    public function test_user_can_add_offers_from_multiple_sellers(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $token = $this->accessToken($buyer);

        $offerA = $this->purchasableOffer(price: '50.00', stock: 10);
        $offerB = $this->purchasableOffer(price: '30.00', stock: 5);

        $this->withToken($token)
            ->postJson('/api/v1/cart/items', [
                'offer_id' => $offerA->id,
                'quantity' => 2,
            ])
            ->assertCreated()
            ->assertJsonPath('data.items_count', 2)
            ->assertJsonPath('data.subtotal', '100.00');

        $response = $this->withToken($token)
            ->postJson('/api/v1/cart/items', [
                'offer_id' => $offerB->id,
                'quantity' => 1,
            ])
            ->assertCreated();

        $response
            ->assertJsonPath('data.items_count', 3)
            ->assertJsonPath('data.subtotal', '130.00')
            ->assertJsonCount(2, 'data.items');

        $this->assertDatabaseCount('cart_items', 2);
        $this->assertNotSame(
            $offerA->store_id,
            $offerB->store_id,
        );
    }

    public function test_adding_same_offer_increments_quantity(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $token = $this->accessToken($buyer);
        $offer = $this->purchasableOffer(price: '10.00', stock: 10);

        $this->withToken($token)
            ->postJson('/api/v1/cart/items', [
                'offer_id' => $offer->id,
                'quantity' => 2,
            ])
            ->assertCreated();

        $this->withToken($token)
            ->postJson('/api/v1/cart/items', [
                'offer_id' => $offer->id,
                'quantity' => 3,
            ])
            ->assertCreated()
            ->assertJsonPath('data.items_count', 5)
            ->assertJsonPath('data.subtotal', '50.00')
            ->assertJsonCount(1, 'data.items');
    }

    public function test_cannot_add_more_than_available_stock(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $offer = $this->purchasableOffer(stock: 2, reserved: 1);

        $this->withToken($this->accessToken($buyer))
            ->postJson('/api/v1/cart/items', [
                'offer_id' => $offer->id,
                'quantity' => 2,
            ])
            ->assertConflict()
            ->assertJsonPath('message', 'Estoque insuficiente para a quantidade solicitada.');
    }

    public function test_cannot_add_paused_offer_or_inactive_store_product(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $token = $this->accessToken($buyer);

        $paused = $this->purchasableOffer();
        $paused->update(['status' => OfferStatus::PAUSED]);

        $this->withToken($token)
            ->postJson('/api/v1/cart/items', [
                'offer_id' => $paused->id,
                'quantity' => 1,
            ])
            ->assertConflict();

        $suspendedStoreOffer = $this->purchasableOffer();
        $suspendedStoreOffer->store->update(['status' => StoreStatus::SUSPENDED]);

        $this->withToken($token)
            ->postJson('/api/v1/cart/items', [
                'offer_id' => $suspendedStoreOffer->id,
                'quantity' => 1,
            ])
            ->assertConflict();

        $inactiveProductOffer = $this->purchasableOffer();
        $inactiveProductOffer->product->update(['status' => ProductStatus::PAUSED]);

        $this->withToken($token)
            ->postJson('/api/v1/cart/items', [
                'offer_id' => $inactiveProductOffer->id,
                'quantity' => 1,
            ])
            ->assertConflict();
    }

    public function test_user_can_update_and_remove_cart_item(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $token = $this->accessToken($buyer);
        $offer = $this->purchasableOffer(price: '20.00', stock: 10);

        $itemId = $this->withToken($token)
            ->postJson('/api/v1/cart/items', [
                'offer_id' => $offer->id,
                'quantity' => 2,
            ])
            ->assertCreated()
            ->json('data.items.0.id');

        $this->withToken($token)
            ->patchJson("/api/v1/cart/items/{$itemId}", [
                'quantity' => 4,
            ])
            ->assertOk()
            ->assertJsonPath('data.subtotal', '80.00')
            ->assertJsonPath('data.items.0.quantity', 4);

        $this->withToken($token)
            ->deleteJson("/api/v1/cart/items/{$itemId}")
            ->assertOk()
            ->assertJsonCount(0, 'data.items')
            ->assertJsonPath('data.subtotal', '0.00');
    }

    public function test_user_cannot_manage_another_users_cart_item(): void
    {
        $owner = $this->userWithRole(RoleName::BUYER);
        $intruder = $this->userWithRole(RoleName::BUYER);
        $offer = $this->purchasableOffer(stock: 5);

        $cart = Cart::factory()->forUser($owner)->create();
        $item = CartItem::factory()->forCart($cart)->forOffer($offer)->create([
            'quantity' => 1,
        ]);

        $token = $this->accessToken($intruder);

        $this->withToken($token)
            ->patchJson("/api/v1/cart/items/{$item->id}", [
                'quantity' => 2,
            ])
            ->assertNotFound();

        $this->withToken($token)
            ->deleteJson("/api/v1/cart/items/{$item->id}")
            ->assertNotFound();
    }

    public function test_user_can_clear_cart(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $token = $this->accessToken($buyer);
        $offer = $this->purchasableOffer(stock: 5);

        $this->withToken($token)
            ->postJson('/api/v1/cart/items', [
                'offer_id' => $offer->id,
                'quantity' => 1,
            ])
            ->assertCreated();

        $this->withToken($token)
            ->deleteJson('/api/v1/cart')
            ->assertNoContent();

        $this->assertDatabaseCount('cart_items', 0);

        $this->withToken($token)
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonCount(0, 'data.items');
    }

    public function test_promotional_price_is_used_in_line_total(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $offer = $this->purchasableOffer(price: '100.00', stock: 5);
        $offer->update([
            'promotional_price' => '80.00',
            'promo_starts_at' => now()->subDay(),
            'promo_ends_at' => now()->addDay(),
        ]);

        $this->withToken($this->accessToken($buyer))
            ->postJson('/api/v1/cart/items', [
                'offer_id' => $offer->id,
                'quantity' => 2,
            ])
            ->assertCreated()
            ->assertJsonPath('data.subtotal', '160.00')
            ->assertJsonPath('data.items.0.unit_price', '80.00');
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
