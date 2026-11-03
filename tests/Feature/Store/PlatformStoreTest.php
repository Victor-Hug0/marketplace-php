<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Enums\LedgerAccountType;
use App\Enums\RoleName;
use App\Enums\StoreStatus;
use App\Models\LedgerEntry;
use App\Models\Offer;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\PlatformStoreSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsPaidOrders;
use Tests\Concerns\ConfiguresShipping;
use Tests\TestCase;

final class PlatformStoreTest extends TestCase
{
    use BuildsPaidOrders;
    use ConfiguresShipping;
    use RefreshDatabase;

    public function test_seeder_creates_a_single_active_platform_store_idempotently(): void
    {
        foreach (RoleName::cases() as $role) {
            Role::query()->firstOrCreate(['name' => $role]);
        }

        $this->seed(PlatformStoreSeeder::class);
        $this->seed(PlatformStoreSeeder::class);

        $store = Store::query()->platform()->sole();
        $this->assertSame(StoreStatus::ACTIVE, $store->status);
        $this->assertSame(config('platform_store.slug'), $store->slug);

        $owner = User::query()->where('email', config('platform_store.owner_email'))->sole();
        $this->assertSame($owner->id, $store->user_id);
        $this->assertTrue($owner->hasRole(RoleName::SELLER));
        $this->assertTrue($owner->hasVerifiedEmail());
        $this->assertFalse(Hash::check('Password1!', $owner->password));
    }

    public function test_database_allows_only_one_platform_store(): void
    {
        Store::factory()->platform()->create();

        $this->expectException(QueryException::class);

        Store::factory()->platform()->create();
    }

    public function test_platform_flag_is_exposed_in_catalog_store_page_and_buy_box(): void
    {
        $platform = Store::factory()->platform()->create();
        $offer = $this->offerFrom($platform, '459.90');
        $thirdParty = $this->purchasableOffer();
        $slug = $offer->product->slug;

        $this->getJson("/api/v1/products/{$slug}")
            ->assertOk()
            ->assertJsonPath('data.store.is_platform', true)
            ->assertJsonPath('data.offers.0.store.is_platform', true)
            ->assertJsonPath('buy_box.store.is_platform', true);

        $this->getJson("/api/v1/products/{$thirdParty->product->slug}")
            ->assertOk()
            ->assertJsonPath('data.store.is_platform', false)
            ->assertJsonPath('buy_box.store.is_platform', false);

        $this->getJson("/api/v1/stores/{$platform->slug}")
            ->assertOk()
            ->assertJsonPath('data.is_platform', true);

        $listing = $this->getJson('/api/v1/products')->assertOk()->json('data');
        $flags = collect($listing)->mapWithKeys(fn (array $product): array => [$product['id'] => $product['store']['is_platform']]);
        $this->assertTrue($flags[$offer->product_id]);
        $this->assertFalse($flags[$thirdParty->product_id]);
    }

    public function test_platform_store_cannot_be_suspended(): void
    {
        $platform = Store::factory()->platform()->create();

        $this->api($this->accessToken($this->userWithRole(RoleName::ADMIN)))
            ->postJson("/api/v1/admin/stores/{$platform->id}/suspend", ['reason' => 'Teste de suspensão da plataforma.'])
            ->assertConflict()
            ->assertJsonPath('message', 'A loja da plataforma não pode ser suspensa.');

        $this->assertSame(StoreStatus::ACTIVE, $platform->fresh()->status);
    }

    public function test_store_request_cannot_set_the_platform_flag(): void
    {
        Notification::fake();
        $buyer = $this->userWithRole(RoleName::BUYER);

        $storeId = $this->api($this->accessToken($buyer))
            ->postJson('/api/v1/stores', [
                'store_name' => 'Loja Esperta',
                'document_type' => 'cnpj',
                'document' => '11222333000181',
                'is_platform' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.is_platform', false)
            ->json('data.id');

        $this->assertFalse(Store::query()->findOrFail($storeId)->isPlatform());
    }

    public function test_platform_sale_follows_the_regular_ledger_with_commission(): void
    {
        $this->useFreeShipping();
        $this->configureMockPayments(commissionBps: 1000);
        $platform = Store::factory()->platform()->create();
        $offer = $this->offerFrom($platform, '200.00');

        $order = $this->paidOrder($this->userWithRole(RoleName::BUYER), [$offer->id => 1]);

        $entries = LedgerEntry::query()
            ->whereIn('order_seller_id', $order->sellers()->pluck('id'))
            ->get();

        $this->assertSame(18000, (int) $entries->where('account_type', LedgerAccountType::SELLER)->where('store_id', $platform->id)->sum('amount_cents'));
        $this->assertSame(2000, (int) $entries->where('account_type', LedgerAccountType::PLATFORM)->sum('amount_cents'));
    }

    private function offerFrom(Store $store, string $price): Offer
    {
        $product = Product::factory()->active()->create(['store_id' => $store->id]);

        return Offer::factory()
            ->active()
            ->withStock(10)
            ->priced($price)
            ->create(['store_id' => $store->id, 'product_id' => $product->id]);
    }
}
