<?php

namespace Tests\Feature\Offer;

use App\Enums\RoleName;
use App\Enums\StoreStatus;
use App\Models\AuditLog;
use App\Models\Offer;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfferPriceCentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_brl_input_is_stored_in_cents_and_exposed_in_both_formats(): void
    {
        [$seller, $store] = $this->sellerWithActiveStore();
        $product = Product::factory()->active()->create();

        $this->withToken($this->accessToken($seller))
            ->postJson('/api/v1/seller/offers', [
                'product_id' => $product->id,
                'price' => 19.99,
                'promotional_price' => 17.49,
                'promo_starts_at' => now()->subDay()->toIso8601String(),
                'promo_ends_at' => now()->addDay()->toIso8601String(),
                'stock' => 3,
            ])
            ->assertCreated()
            ->assertJsonPath('data.price', '19.99')
            ->assertJsonPath('data.price_cents', 1999)
            ->assertJsonPath('data.promotional_price', '17.49')
            ->assertJsonPath('data.promotional_price_cents', 1749)
            ->assertJsonPath('data.current_price', '17.49')
            ->assertJsonPath('data.current_price_cents', 1749);

        $this->assertDatabaseHas('offers', [
            'store_id' => $store->id,
            'price_cents' => 1999,
            'promotional_price_cents' => 1749,
        ]);
    }

    public function test_update_converts_price_and_audits_cents(): void
    {
        [$seller, $store] = $this->sellerWithActiveStore();
        $offer = Offer::factory()->active()->priced('100.00')->create(['store_id' => $store->id]);

        $this->withToken($this->accessToken($seller))
            ->putJson("/api/v1/seller/offers/{$offer->id}", ['price' => '25.50'])
            ->assertOk()
            ->assertJsonPath('data.price_cents', 2550);

        $this->assertSame(2550, $offer->refresh()->price_cents);
        $audit = AuditLog::query()->where('action', 'offer.updated')->latest('created_at')->firstOrFail();
        $this->assertSame(2550, $audit->payload['price_cents']);
    }

    public function test_cart_totals_are_exposed_in_cents(): void
    {
        $buyer = User::factory()->create();
        $buyer->roles()->syncWithoutDetaching([Role::query()->firstOrCreate(['name' => RoleName::BUYER])->id]);
        $offer = Offer::factory()->active()->withStock(10)->priced('33.33')->create();
        $token = $this->accessToken($buyer);

        $this->withToken($token)
            ->postJson('/api/v1/cart/items', ['offer_id' => $offer->id, 'quantity' => 3])
            ->assertSuccessful();

        $this->withToken($token)
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.items.0.unit_price', '33.33')
            ->assertJsonPath('data.items.0.unit_price_cents', 3333)
            ->assertJsonPath('data.items.0.line_total', '99.99')
            ->assertJsonPath('data.items.0.line_total_cents', 9999)
            ->assertJsonPath('data.subtotal', '99.99')
            ->assertJsonPath('data.subtotal_cents', 9999);
    }

    public function test_database_rejects_promotional_price_not_below_regular_price(): void
    {
        $this->expectException(QueryException::class);

        Offer::factory()->create(['price_cents' => 1000, 'promotional_price_cents' => 1000]);
    }

    public function test_database_rejects_non_positive_price(): void
    {
        $this->expectException(QueryException::class);

        Offer::factory()->create(['price_cents' => 0]);
    }

    public function test_migration_converts_existing_decimal_prices_to_cents(): void
    {
        $migration = require database_path('migrations/2026_10_20_700000_convert_offer_prices_to_cents.php');
        $product = Product::factory()->active()->create();
        $store = Store::factory()->active()->create();

        $migration->down();

        $withPromo = (string) Str::uuid();
        $withoutPromo = (string) Str::uuid();
        foreach ([[$withPromo, '1999.90', '1799.90'], [$withoutPromo, '0.10', null]] as [$id, $price, $promo]) {
            DB::table('offers')->insert([
                'id' => $id,
                'product_id' => $product->id,
                'store_id' => $store->id,
                'price' => $price,
                'promotional_price' => $promo,
                'stock' => 1,
                'reserved_stock' => 0,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $store = Store::factory()->active()->create();
        }

        $migration->up();

        $this->assertDatabaseHas('offers', ['id' => $withPromo, 'price_cents' => 199990, 'promotional_price_cents' => 179990]);
        $this->assertDatabaseHas('offers', ['id' => $withoutPromo, 'price_cents' => 10, 'promotional_price_cents' => null]);
    }

    /**
     * @return array{User, Store}
     */
    private function sellerWithActiveStore(): array
    {
        $user = User::factory()->create();
        foreach ([RoleName::BUYER, RoleName::SELLER] as $role) {
            $user->roles()->syncWithoutDetaching([Role::query()->firstOrCreate(['name' => $role])->id]);
        }

        $store = Store::factory()->active()->create(['user_id' => $user->id, 'status' => StoreStatus::ACTIVE]);

        return [$user->fresh(), $store];
    }

    private function accessToken(User $user): string
    {
        return $user->createToken('access_token', ['access'], now()->addHour())->plainTextToken;
    }
}
