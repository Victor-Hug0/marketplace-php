<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Enums\RoleName;
use App\Enums\ShippingZone;
use App\Models\Address;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderSeller;
use App\Models\Product;
use App\Models\Role;
use App\Models\ShippingRateRule;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\ShippingRateRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class ShippingQuoteTest extends TestCase
{
    use RefreshDatabase;

    private const DESTINATION_CEP = '01311000';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ShippingRateRuleSeeder::class);
    }

    public function test_guest_cannot_quote_shipping(): void
    {
        $this->postJson('/api/v1/shipping/quotes', ['cep' => self::DESTINATION_CEP])
            ->assertUnauthorized();
    }

    public function test_quote_requires_cep_or_address(): void
    {
        $buyer = $this->buyer();

        $this->withToken($this->accessToken($buyer))
            ->postJson('/api/v1/shipping/quotes', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cep', 'address_id']);

        $this->withToken($this->accessToken($buyer))
            ->postJson('/api/v1/shipping/quotes', ['cep' => '123'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cep']);
    }

    public function test_empty_cart_cannot_be_quoted(): void
    {
        $this->withToken($this->accessToken($this->buyer()))
            ->postJson('/api/v1/shipping/quotes', ['cep' => self::DESTINATION_CEP])
            ->assertConflict();
    }

    public function test_quote_returns_one_entry_per_seller_with_zone_and_cheapest_first(): void
    {
        $buyer = $this->buyer();
        $token = $this->accessToken($buyer);

        $local = $this->offerFrom(originCep: '01310100');
        $regional = $this->offerFrom(originCep: '04538000');
        $national = $this->offerFrom(originCep: '90010000');

        foreach ([$local, $regional, $national] as $offer) {
            $this->addToCart($token, $offer, 1);
        }

        $quotes = collect(
            $this->withToken($token)
                ->postJson('/api/v1/shipping/quotes', ['cep' => '01311-000'])
                ->assertOk()
                ->assertJsonCount(3, 'data')
                ->json('data'),
        )->keyBy('store.id');

        $this->assertSame(ShippingZone::LOCAL->value, $quotes[$local->store_id]['zone']);
        $this->assertSame(ShippingZone::REGIONAL->value, $quotes[$regional->store_id]['zone']);
        $this->assertSame(ShippingZone::NATIONAL->value, $quotes[$national->store_id]['zone']);

        $localOptions = $quotes[$local->store_id]['options'];
        $this->assertSame(['simulated_standard', 'simulated_express'], array_column($localOptions, 'service_code'));
        $this->assertSame([1290, 2190], array_column($localOptions, 'price_cents'));
        $this->assertSame([3, 1], array_column($localOptions, 'eta_days'));

        $this->assertSame(2590, $quotes[$national->store_id]['options'][0]['price_cents']);
    }

    public function test_quote_by_address_uses_only_the_buyers_own_address(): void
    {
        $buyer = $this->buyer();
        $token = $this->accessToken($buyer);
        $this->addToCart($token, $this->offerFrom(originCep: '01310100'), 1);

        $own = Address::factory()->belongingToUser($buyer)->create(['cep' => '01311000']);
        $foreign = Address::factory()->belongingToUser($this->buyer())->create(['cep' => '90010000']);

        $this->withToken($token)
            ->postJson('/api/v1/shipping/quotes', ['address_id' => $own->id])
            ->assertOk()
            ->assertJsonPath('data.0.zone', ShippingZone::LOCAL->value)
            ->assertJsonPath('data.0.destination_cep', '01311000');

        $this->withToken($token)
            ->postJson('/api/v1/shipping/quotes', ['address_id' => $foreign->id])
            ->assertNotFound();
    }

    public function test_billable_weight_uses_volumetric_weight_and_default_package(): void
    {
        $buyer = $this->buyer();
        $token = $this->accessToken($buyer);

        $bulky = $this->offerFrom(
            originCep: '01310100',
            dimensions: ['weight_grams' => 1000, 'length_cm' => 60, 'width_cm' => 40, 'height_cm' => 40],
        );
        $withoutDimensions = $this->offerFrom(originCep: '01310100');

        $this->addToCart($token, $bulky, 1);
        $this->addToCart($token, $withoutDimensions, 3);

        $quotes = collect(
            $this->withToken($token)
                ->postJson('/api/v1/shipping/quotes', ['cep' => self::DESTINATION_CEP])
                ->assertOk()
                ->json('data'),
        )->keyBy('store.id');

        // 60 x 40 x 40 cm / 6000 = 16 kg volumetric, heavier than the real 1 kg.
        $this->assertSame(16000, $quotes[$bulky->store_id]['billable_weight_grams']);
        $this->assertSame(3490, $quotes[$bulky->store_id]['options'][0]['price_cents']);

        // Default package: 500 g real beats 16 x 11 x 6 cm volumetric.
        $this->assertSame(1500, $quotes[$withoutDimensions->store_id]['billable_weight_grams']);
    }

    public function test_checkout_freezes_shipping_per_sub_order(): void
    {
        $buyer = $this->buyer();
        $token = $this->accessToken($buyer);
        $address = Address::factory()->belongingToUser($buyer)->create(['cep' => self::DESTINATION_CEP]);

        $local = $this->offerFrom(originCep: '01310100', price: '100.00');
        $national = $this->offerFrom(originCep: '90010000', price: '50.00');
        $this->addToCart($token, $local, 1);
        $this->addToCart($token, $national, 2);

        $orderId = $this->checkout($token, $address)
            ->assertCreated()
            ->assertJsonPath('data.subtotal_cents', 20000)
            ->assertJsonPath('data.shipping_cents', 1290 + 2590)
            ->assertJsonPath('data.total_cents', 20000 + 1290 + 2590)
            ->json('data.id');

        $localSubOrder = $this->subOrder($orderId, $local);
        $this->assertSame(1290, $localSubOrder->shipping_cents);
        $this->assertSame(10000 + 1290, $localSubOrder->total_cents);
        $this->assertSame('simulated_standard', $localSubOrder->shipping_service_code);
        $this->assertSame(3, $localSubOrder->shipping_eta_days);
        $this->assertNotNull($localSubOrder->shipping_quoted_at);

        $this->assertSame(2590, $this->subOrder($orderId, $national)->shipping_cents);

        ShippingRateRule::query()->update(['price_cents' => 99999, 'eta_days' => 30]);

        $this->assertSame(1290, $localSubOrder->fresh()->shipping_cents);
        $this->assertSame(20000 + 1290 + 2590, Order::query()->findOrFail($orderId)->total_cents);
    }

    public function test_checkout_uses_requested_shipping_service(): void
    {
        $buyer = $this->buyer();
        $token = $this->accessToken($buyer);
        $address = Address::factory()->belongingToUser($buyer)->create(['cep' => self::DESTINATION_CEP]);
        $offer = $this->offerFrom(originCep: '01310100', price: '100.00');
        $this->addToCart($token, $offer, 1);

        $orderId = $this->checkout($token, $address, ['shipping_service_code' => 'simulated_express'])
            ->assertCreated()
            ->assertJsonPath('data.shipping_cents', 2190)
            ->json('data.id');

        $subOrder = $this->subOrder($orderId, $offer);
        $this->assertSame('simulated_express', $subOrder->shipping_service_code);
        $this->assertSame(1, $subOrder->shipping_eta_days);
    }

    public function test_checkout_fails_without_creating_order_when_shipping_is_unavailable(): void
    {
        $buyer = $this->buyer();
        $token = $this->accessToken($buyer);
        $address = Address::factory()->belongingToUser($buyer)->create(['cep' => self::DESTINATION_CEP]);
        $offer = $this->offerFrom(originCep: '01310100');
        $this->addToCart($token, $offer, 1);

        $this->checkout($token, $address, ['shipping_service_code' => 'teleporte'])
            ->assertConflict();

        $heavy = $this->offerFrom(originCep: '01310100', dimensions: ['weight_grams' => 40000]);
        $this->addToCart($token, $heavy, 1);

        $this->checkout($token, $address)->assertConflict();

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(0, $offer->fresh()->reserved_stock);
        $this->assertDatabaseCount('cart_items', 2);
    }

    public function test_zone_is_derived_from_cep_prefixes(): void
    {
        $this->assertSame(ShippingZone::LOCAL, ShippingZone::between('01310100', '01311000'));
        $this->assertSame(ShippingZone::REGIONAL, ShippingZone::between('04538000', '01311000'));
        $this->assertSame(ShippingZone::NATIONAL, ShippingZone::between('90010000', '01311000'));
    }

    /**
     * @param  array<string, int>  $dimensions
     */
    private function offerFrom(string $originCep, string $price = '99.90', array $dimensions = []): Offer
    {
        $store = Store::factory()->active()->create(['origin_cep' => $originCep]);
        $product = Product::factory()->active()->create([
            'store_id' => $store->id,
            ...$dimensions,
        ]);

        return Offer::factory()
            ->active()
            ->withStock(10, 0)
            ->priced($price)
            ->create([
                'store_id' => $store->id,
                'product_id' => $product->id,
            ]);
    }

    private function addToCart(string $token, Offer $offer, int $quantity): void
    {
        $this->withToken($token)
            ->postJson('/api/v1/cart/items', ['offer_id' => $offer->id, 'quantity' => $quantity])
            ->assertCreated();
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function checkout(string $token, Address $address, array $extra = []): TestResponse
    {
        return $this->withToken($token)->postJson(
            '/api/v1/checkout',
            ['address_id' => $address->id, ...$extra],
            ['Idempotency-Key' => 'shipping-'.Str::uuid()],
        );
    }

    private function subOrder(string $orderId, Offer $offer): OrderSeller
    {
        return OrderSeller::query()
            ->where('order_id', $orderId)
            ->where('store_id', $offer->store_id)
            ->firstOrFail();
    }

    private function buyer(): User
    {
        $user = User::factory()->create();
        $user->roles()->syncWithoutDetaching([
            Role::query()->firstOrCreate(['name' => RoleName::BUYER])->id,
        ]);

        return $user;
    }

    private function accessToken(User $user): string
    {
        $this->app['auth']->forgetGuards();

        return $user->createToken('access_token', ['access'], now()->addHour())->plainTextToken;
    }
}
