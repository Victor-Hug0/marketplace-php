<?php

declare(strict_types=1);

namespace Tests\Feature\Offer;

use App\Enums\OfferStatus;
use App\Enums\ProductStatus;
use App\Enums\ReservationStatus;
use App\Enums\RoleName;
use App\Enums\StoreStatus;
use App\Models\Category;
use App\Models\InventoryReservation;
use App\Models\Offer;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

final class OfferWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_seller_can_create_offer_on_another_stores_product(): void
    {
        [$sellerA, $storeA, $category] = $this->sellerWithActiveStore();
        [$sellerB, $storeB] = $this->sellerWithActiveStore([
            'email' => 'seller-b@teste.com',
            'cpf' => '98765432100',
            'document' => '11222333000181',
        ]);

        $product = Product::factory()->active()->create([
            'store_id' => $storeA->id,
            'category_id' => $category->id,
            'slug' => 'produto-multi-seller',
            'status' => ProductStatus::ACTIVE,
        ]);

        $response = $this->withToken($this->accessToken($sellerB))
            ->postJson('/api/v1/seller/offers', [
                'product_id' => $product->id,
                'price' => '150.00',
                'stock' => 5,
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.status', OfferStatus::ACTIVE->value)
            ->assertJsonPath('data.current_price', '150.00')
            ->assertJsonPath('data.available_stock', 5);

        $this->assertDatabaseHas('offers', [
            'product_id' => $product->id,
            'store_id' => $storeB->id,
            'price_cents' => 15000,
        ]);
    }

    public function test_duplicate_offer_for_same_store_and_product_conflicts(): void
    {
        [$seller, $store, $category] = $this->sellerWithActiveStore();
        $product = Product::factory()->active()->create([
            'store_id' => $store->id,
            'category_id' => $category->id,
        ]);

        Offer::factory()
            ->onProduct($product)
            ->belongingToStore($store)
            ->priced('100.00')
            ->withStock(3)
            ->create();

        $this->withToken($this->accessToken($seller))
            ->postJson('/api/v1/seller/offers', [
                'product_id' => $product->id,
                'price' => '90.00',
                'stock' => 2,
            ])
            ->assertConflict()
            ->assertJsonPath('message', 'Esta loja já possui uma oferta para este produto.');
    }

    public function test_buy_box_picks_lowest_current_price_then_stock_then_oldest(): void
    {
        [$sellerA, $storeA, $category] = $this->sellerWithActiveStore();
        [, $storeB] = $this->sellerWithActiveStore([
            'email' => 'seller-b@teste.com',
            'cpf' => '11122233344',
            'document' => '99888777000166',
        ]);
        [, $storeC] = $this->sellerWithActiveStore([
            'email' => 'seller-c@teste.com',
            'cpf' => '55566677788',
            'document' => '55444333000122',
        ]);

        $product = Product::factory()->active()->create([
            'store_id' => $storeA->id,
            'category_id' => $category->id,
            'slug' => 'buy-box-produto',
            'status' => ProductStatus::ACTIVE,
        ]);

        $expensive = Offer::factory()
            ->onProduct($product)
            ->belongingToStore($storeA)
            ->priced('200.00')
            ->withStock(50)
            ->create(['created_at' => now()->subDays(3)]);

        $cheapHighStock = Offer::factory()
            ->onProduct($product)
            ->belongingToStore($storeB)
            ->priced('100.00')
            ->withStock(20)
            ->create(['created_at' => now()->subDays(2)]);

        $cheapLowStockOlder = Offer::factory()
            ->onProduct($product)
            ->belongingToStore($storeC)
            ->priced('100.00')
            ->withStock(5)
            ->create(['created_at' => now()->subDays(5)]);

        $response = $this->getJson('/api/v1/products/buy-box-produto');

        $response
            ->assertOk()
            ->assertJsonPath('buy_box.id', $cheapHighStock->id)
            ->assertJsonPath('buy_box.current_price', '100.00')
            ->assertJsonCount(3, 'data.offers');

        $this->assertNotSame($expensive->id, $response->json('buy_box.id'));
        $this->assertNotSame($cheapLowStockOlder->id, $response->json('buy_box.id'));
    }

    public function test_paused_offer_and_suspended_store_are_excluded_from_buy_box_and_reservations(): void
    {
        [$sellerA, $storeA, $category] = $this->sellerWithActiveStore();
        [$sellerB, $storeB] = $this->sellerWithActiveStore([
            'email' => 'seller-b@teste.com',
            'cpf' => '11122233344',
            'document' => '99888777000166',
        ]);
        $buyer = $this->userWithRole(RoleName::BUYER);

        $product = Product::factory()->active()->create([
            'store_id' => $storeA->id,
            'category_id' => $category->id,
            'slug' => 'oferta-pausada',
            'status' => ProductStatus::ACTIVE,
        ]);

        $paused = Offer::factory()
            ->onProduct($product)
            ->belongingToStore($storeA)
            ->priced('50.00')
            ->withStock(10)
            ->paused()
            ->create();

        $activeThenSuspend = Offer::factory()
            ->onProduct($product)
            ->belongingToStore($storeB)
            ->priced('80.00')
            ->withStock(10)
            ->create();

        $storeB->update(['status' => StoreStatus::SUSPENDED]);

        $this->getJson('/api/v1/products/oferta-pausada')
            ->assertOk()
            ->assertJsonPath('buy_box', null)
            ->assertJsonCount(0, 'data.offers');

        $this->withToken($this->accessToken($buyer))
            ->postJson("/api/v1/offers/{$paused->id}/reservations", ['quantity' => 1])
            ->assertConflict();

        $this->withToken($this->accessToken($buyer))
            ->postJson("/api/v1/offers/{$activeThenSuspend->id}/reservations", ['quantity' => 1])
            ->assertConflict();
    }

    public function test_reservation_consumes_stock_and_second_last_unit_conflicts(): void
    {
        [$seller, $store, $category] = $this->sellerWithActiveStore();
        $buyerA = $this->userWithRole(RoleName::BUYER);
        $buyerB = User::factory()->create([
            'email' => 'buyer-b@teste.com',
            'cpf' => '12312312312',
        ]);
        $this->assignRole($buyerB, RoleName::BUYER);

        $product = Product::factory()->active()->create([
            'store_id' => $store->id,
            'category_id' => $category->id,
        ]);

        $offer = Offer::factory()
            ->onProduct($product)
            ->belongingToStore($store)
            ->priced('99.90')
            ->withStock(1)
            ->create();

        $first = $this->withToken($this->accessToken($buyerA))
            ->postJson("/api/v1/offers/{$offer->id}/reservations", ['quantity' => 1]);

        $first
            ->assertCreated()
            ->assertJsonPath('data.quantity', 1)
            ->assertJsonPath('data.status', ReservationStatus::ACTIVE->value);

        $this->assertSame(0, $offer->fresh()->availableStock());

        $this->withToken($this->accessToken($buyerB))
            ->postJson("/api/v1/offers/{$offer->id}/reservations", ['quantity' => 1])
            ->assertConflict()
            ->assertJsonPath('message', 'Estoque insuficiente para reservar a quantidade solicitada.');
    }

    public function test_concurrent_reservations_of_last_unit_allow_only_one_success(): void
    {
        [$seller, $store, $category] = $this->sellerWithActiveStore();
        $buyerA = $this->userWithRole(RoleName::BUYER);
        $buyerB = User::factory()->create([
            'email' => 'buyer-c@teste.com',
            'cpf' => '32132132132',
        ]);
        $this->assignRole($buyerB, RoleName::BUYER);

        $product = Product::factory()->active()->create([
            'store_id' => $store->id,
            'category_id' => $category->id,
        ]);

        $offer = Offer::factory()
            ->onProduct($product)
            ->belongingToStore($store)
            ->priced('10.00')
            ->withStock(1)
            ->create();

        /** @var InventoryService $inventory */
        $inventory = $this->app->make(InventoryService::class);

        $successes = 0;
        $failures = 0;

        $attempt = function (User $user) use ($inventory, $offer, &$successes, &$failures): void {
            try {
                DB::transaction(function () use ($inventory, $offer, $user): void {
                    $inventory->reserve($offer->fresh(), $user, 1);
                });
                $successes++;
            } catch (ConflictHttpException) {
                $failures++;
            }
        };

        $attempt($buyerA);
        $attempt($buyerB);

        $this->assertSame(1, $successes);
        $this->assertSame(1, $failures);
        $this->assertSame(0, $offer->fresh()->availableStock());
        $this->assertSame(1, $offer->fresh()->reserved_stock);
    }

    public function test_release_returns_stock_and_commit_decrements_stock(): void
    {
        [$seller, $store, $category] = $this->sellerWithActiveStore();
        $buyer = $this->userWithRole(RoleName::BUYER);

        $product = Product::factory()->active()->create([
            'store_id' => $store->id,
            'category_id' => $category->id,
        ]);

        $offer = Offer::factory()
            ->onProduct($product)
            ->belongingToStore($store)
            ->priced('40.00')
            ->withStock(5)
            ->create();

        $reserve = $this->withToken($this->accessToken($buyer))
            ->postJson("/api/v1/offers/{$offer->id}/reservations", ['quantity' => 2])
            ->assertCreated();

        $reservationId = $reserve->json('data.id');

        $this->withToken($this->accessToken($buyer))
            ->deleteJson("/api/v1/offers/{$offer->id}/reservations/{$reservationId}")
            ->assertNoContent();

        $this->assertSame(5, $offer->fresh()->availableStock());
        $this->assertSame(
            ReservationStatus::RELEASED,
            InventoryReservation::query()->findOrFail($reservationId)->status,
        );

        /** @var InventoryService $inventory */
        $inventory = $this->app->make(InventoryService::class);
        $toCommit = $inventory->reserve($offer->fresh(), $buyer, 2);
        $inventory->commit($toCommit);

        $offer->refresh();
        $this->assertSame(3, $offer->stock);
        $this->assertSame(0, $offer->reserved_stock);
        $this->assertSame(ReservationStatus::COMMITTED, $toCommit->fresh()->status);
    }

    public function test_seller_cannot_edit_another_sellers_offer(): void
    {
        [$sellerA] = $this->sellerWithActiveStore();
        [, $storeB, $category] = $this->sellerWithActiveStore([
            'email' => 'seller-b@teste.com',
            'cpf' => '11122233344',
            'document' => '99888777000166',
        ]);

        $product = Product::factory()->active()->create([
            'store_id' => $storeB->id,
            'category_id' => $category->id,
        ]);

        $offer = Offer::factory()
            ->onProduct($product)
            ->belongingToStore($storeB)
            ->priced('70.00')
            ->withStock(4)
            ->create();

        $this->withToken($this->accessToken($sellerA))
            ->putJson("/api/v1/seller/offers/{$offer->id}", [
                'price' => '1.00',
            ])
            ->assertForbidden();
    }

    public function test_seller_can_pause_and_resume_own_offer(): void
    {
        [$seller, $store, $category] = $this->sellerWithActiveStore();
        $product = Product::factory()->active()->create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'slug' => 'pause-offer',
            'status' => ProductStatus::ACTIVE,
        ]);

        $offer = Offer::factory()
            ->onProduct($product)
            ->belongingToStore($store)
            ->priced('55.00')
            ->withStock(8)
            ->create();

        $token = $this->accessToken($seller);

        $this->withToken($token)
            ->postJson("/api/v1/seller/offers/{$offer->id}/pause")
            ->assertOk()
            ->assertJsonPath('data.status', OfferStatus::PAUSED->value);

        $this->getJson('/api/v1/products/pause-offer')
            ->assertOk()
            ->assertJsonPath('buy_box', null);

        $this->withToken($token)
            ->postJson("/api/v1/seller/offers/{$offer->id}/resume")
            ->assertOk()
            ->assertJsonPath('data.status', OfferStatus::ACTIVE->value);

        $this->getJson('/api/v1/products/pause-offer')
            ->assertOk()
            ->assertJsonPath('buy_box.id', $offer->id);
    }

    /**
     * @param  array{email?: string, cpf?: string, document?: string}  $overrides
     * @return array{0: User, 1: Store, 2: Category}
     */
    private function sellerWithActiveStore(array $overrides = []): array
    {
        $user = User::factory()->create([
            'email' => $overrides['email'] ?? fake()->unique()->safeEmail(),
            'cpf' => $overrides['cpf'] ?? fake()->unique()->numerify('###########'),
        ]);
        $this->assignRole($user, RoleName::BUYER);
        $this->assignRole($user, RoleName::SELLER);

        $store = Store::factory()->active()->create([
            'user_id' => $user->id,
            'document' => $overrides['document'] ?? fake()->unique()->numerify('##############'),
            'status' => StoreStatus::ACTIVE,
        ]);

        $category = Category::factory()->create();

        return [$user->fresh(), $store, $category];
    }

    private function userWithRole(RoleName $role): User
    {
        $user = User::factory()->create();
        $this->assignRole($user, $role);

        return $user;
    }

    private function assignRole(User $user, RoleName $role): void
    {
        $roleModel = Role::query()->firstOrCreate(['name' => $role]);
        $user->roles()->syncWithoutDetaching([$roleModel->id]);
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
