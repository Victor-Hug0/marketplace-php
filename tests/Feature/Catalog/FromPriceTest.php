<?php

namespace Tests\Feature\Catalog;

use App\Enums\StoreStatus;
use App\Models\Offer;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FromPriceTest extends TestCase
{
    use RefreshDatabase;

    public function test_listing_shows_lowest_current_price_among_purchasable_offers(): void
    {
        $product = Product::factory()->active()->create();

        Offer::factory()->active()->withStock(5)->priced('120.00')->create(['product_id' => $product->id]);
        Offer::factory()->active()->withStock(5)->priced('150.00', '99.90')->create(['product_id' => $product->id]);
        Offer::factory()->paused()->withStock(5)->priced('10.00')->create(['product_id' => $product->id]);
        Offer::factory()->active()->withStock(3, 3)->priced('20.00')->create(['product_id' => $product->id]);
        Offer::factory()->active()->withStock(5)->priced('30.00')->create([
            'product_id' => $product->id,
            'store_id' => Store::factory()->create(['status' => StoreStatus::SUSPENDED])->id,
        ]);

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('data.0.from_price', '99.90')
            ->assertJsonPath('data.0.from_price_cents', 9990)
            ->assertJsonPath('data.0.available_offers_count', 2);
    }

    public function test_expired_promotion_is_ignored(): void
    {
        $product = Product::factory()->active()->create();
        Offer::factory()->active()->withStock(5)->create([
            'product_id' => $product->id,
            'price_cents' => 5000,
            'promotional_price_cents' => 4000,
            'promo_starts_at' => now()->subDays(10),
            'promo_ends_at' => now()->subDay(),
        ]);

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('data.0.from_price_cents', 5000);
    }

    public function test_product_without_purchasable_offers_has_null_from_price(): void
    {
        Product::factory()->active()->create();

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('data.0.from_price', null)
            ->assertJsonPath('data.0.from_price_cents', null)
            ->assertJsonPath('data.0.available_offers_count', 0);
    }

    public function test_store_showcase_lists_products_with_from_price(): void
    {
        $store = Store::factory()->active()->create();
        $product = Product::factory()->active()->create(['store_id' => $store->id]);
        Offer::factory()->active()->withStock(5)->priced('45.00')->create(['product_id' => $product->id, 'store_id' => $store->id]);

        $this->getJson("/api/v1/stores/{$store->slug}")
            ->assertOk()
            ->assertJsonPath('products.data.0.from_price_cents', 4500);
    }

    public function test_from_price_does_not_add_queries_per_product(): void
    {
        $this->seedProductsWithOffers(1);
        $single = $this->countQueries(fn () => $this->getJson('/api/v1/products')->assertOk());

        $this->seedProductsWithOffers(5);
        $many = $this->countQueries(fn () => $this->getJson('/api/v1/products')->assertOk());

        $this->assertSame($single, $many);
    }

    private function seedProductsWithOffers(int $count): void
    {
        Product::factory()->active()->count($count)->create()->each(
            fn (Product $product) => Offer::factory()->active()->withStock(5)->priced('10.00')->create(['product_id' => $product->id]),
        );
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
