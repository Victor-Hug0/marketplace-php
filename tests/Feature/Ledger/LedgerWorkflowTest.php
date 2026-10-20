<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use App\Enums\LedgerAccountType;
use App\Enums\LedgerEntryReason;
use App\Enums\OrderSellerStatus;
use App\Enums\PaymentMethod;
use App\Enums\RoleName;
use App\Models\Address;
use App\Models\LedgerEntry;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderSeller;
use App\Models\Payment;
use App\Models\PlatformFeeRule;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\PlatformFeeService;
use Database\Seeders\PlatformFeeRuleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Concerns\ConfiguresShipping;
use Tests\TestCase;

final class LedgerWorkflowTest extends TestCase
{
    use ConfiguresShipping;
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'test-mock-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useFreeShipping();

        config([
            'payments.mock.webhook_secret' => self::WEBHOOK_SECRET,
            'payments.commission.default_percent_bps' => 1000,
        ]);
    }

    public function test_paid_order_credits_each_seller_and_platform_with_default_commission(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $token = $this->accessToken($buyer);
        $offerA = $this->purchasableOffer(price: '100.00');
        $offerB = $this->purchasableOffer(price: '30.00');
        $order = $this->checkoutOrder($buyer, $token, [$offerA->id => 1, $offerB->id => 1]);

        $payment = $this->payOrder($token, $order);

        $this->assertSame([9000, 1000], $this->amountsFor($order, $offerA->store));
        $this->assertSame([2700, 300], $this->amountsFor($order, $offerB->store));

        $entries = LedgerEntry::query()->where('payment_id', $payment->id)->get();
        $this->assertCount(4, $entries);
        $this->assertSame($payment->amount_cents, (int) $entries->sum('amount_cents'));
        $this->assertSame($order->total_cents, (int) $entries->sum('amount_cents'));
        $this->assertTrue($entries->every(
            fn (LedgerEntry $entry): bool => $entry->platform_fee_rule_id === null
                && $entry->metadata['rule_source'] === 'config',
        ));
    }

    public function test_rule_in_effect_overrides_config_and_is_recorded(): void
    {
        PlatformFeeRule::factory()->percentage(5000)->expired()->create();
        PlatformFeeRule::factory()->percentage(9000)->future()->create();
        $active = PlatformFeeRule::factory()->percentage(1250)->create();

        $buyer = $this->userWithRole(RoleName::BUYER);
        $token = $this->accessToken($buyer);
        $offer = $this->purchasableOffer(price: '80.00');
        $order = $this->checkoutOrder($buyer, $token, [$offer->id => 1]);

        $this->payOrder($token, $order);

        $this->assertSame([7000, 1000], $this->amountsFor($order, $offer->store));
        $this->assertSame(
            2,
            LedgerEntry::query()->where('platform_fee_rule_id', $active->id)->count(),
        );
    }

    public function test_fixed_fee_rule_applies_per_sub_order(): void
    {
        PlatformFeeRule::factory()->fixed(500)->create();

        $buyer = $this->userWithRole(RoleName::BUYER);
        $token = $this->accessToken($buyer);
        $offer = $this->purchasableOffer(price: '30.00');
        $order = $this->checkoutOrder($buyer, $token, [$offer->id => 2]);

        $this->payOrder($token, $order);

        $this->assertSame([5500, 500], $this->amountsFor($order, $offer->store));
    }

    public function test_fee_calculation_rounds_half_up_and_caps_fixed_fee(): void
    {
        $service = app(PlatformFeeService::class);

        $this->assertSame(101, $service->calculate(1005)->feeCents);
        $this->assertSame(100, $service->calculate(1004)->feeCents);
        $this->assertSame(0, $service->calculate(0)->feeCents);

        PlatformFeeRule::factory()->fixed(5000)->create();

        $this->assertSame(3000, $service->calculate(3000)->feeCents);
    }

    public function test_duplicate_or_repeated_paid_webhooks_do_not_duplicate_entries(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $token = $this->accessToken($buyer);
        $offer = $this->purchasableOffer(price: '50.00');
        $order = $this->checkoutOrder($buyer, $token, [$offer->id => 1]);
        $externalId = $this->createPayment($token, $order)->json('data.external_id');

        $this->sendWebhook('evt_ledger_1', $externalId)->assertAccepted();
        $this->sendWebhook('evt_ledger_1', $externalId)->assertOk();
        $this->sendWebhook('evt_ledger_2', $externalId)->assertAccepted();

        $this->assertSame(2, LedgerEntry::query()->count());
    }

    public function test_post_sale_is_idempotent_and_requires_paid_sub_order(): void
    {
        $service = app(LedgerService::class);
        $orderSeller = OrderSeller::factory()->withTotals(10000)->create([
            'status' => OrderSellerStatus::PAID,
        ]);

        $first = $service->postSale($orderSeller);
        $second = $service->postSale($orderSeller);

        $this->assertCount(2, $first);
        $this->assertEqualsCanonicalizing($first->modelKeys(), $second->modelKeys());
        $this->assertSame(2, LedgerEntry::query()->count());

        $pending = OrderSeller::factory()->create();

        $this->expectException(ConflictHttpException::class);
        $service->postSale($pending);
    }

    public function test_cancelled_sub_order_receives_no_entries(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $token = $this->accessToken($buyer);
        $offerA = $this->purchasableOffer(price: '50.00');
        $offerB = $this->purchasableOffer(price: '30.00');
        $order = $this->checkoutOrder($buyer, $token, [$offerA->id => 1, $offerB->id => 1]);

        $orderSellerB = OrderSeller::query()
            ->where('order_id', $order->id)
            ->where('store_id', $offerB->store_id)
            ->firstOrFail();

        $this->withToken($this->accessToken($this->sellerOf($offerB->store)))
            ->postJson("/api/v1/seller/order-sellers/{$orderSellerB->id}/cancel")
            ->assertOk();

        $payment = $this->payOrder($this->accessToken($buyer), $order);

        $this->assertSame(0, LedgerEntry::query()->where('order_seller_id', $orderSellerB->id)->count());
        $this->assertSame(5000, (int) LedgerEntry::query()->sum('amount_cents'));
        $this->assertSame($payment->amount_cents, (int) LedgerEntry::query()->sum('amount_cents'));
    }

    public function test_seller_sees_only_own_balance_and_entries(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $token = $this->accessToken($buyer);
        $offerA = $this->purchasableOffer(price: '100.00', stock: 10);
        $offerB = $this->purchasableOffer(price: '30.00', stock: 10);

        $this->payOrder($token, $this->checkoutOrder($buyer, $token, [$offerA->id => 1]));
        $this->payOrder($token, $this->checkoutOrder($buyer, $token, [$offerA->id => 2, $offerB->id => 1]));

        $sellerToken = $this->accessToken($this->sellerOf($offerA->store));

        $this->withToken($sellerToken)
            ->getJson('/api/v1/seller/balance')
            ->assertOk()
            ->assertJsonPath('data.store_id', $offerA->store_id)
            ->assertJsonPath('data.balance_cents', 27000)
            ->assertJsonPath('data.currency', 'BRL');

        $this->withToken($sellerToken)
            ->getJson('/api/v1/seller/ledger-entries')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.account_type', LedgerAccountType::SELLER->value)
            ->assertJsonPath('data.0.reason', LedgerEntryReason::SALE->value)
            ->assertJsonPath('data.0.amount_cents', 18000)
            ->assertJsonPath('data.0.commission.fee_cents', 2000)
            ->assertJsonPath('data.0.commission.percent_bps', 1000)
            ->assertJsonPath('data.1.amount_cents', 9000);

        $this->withToken($this->accessToken($this->sellerOf($offerB->store)))
            ->getJson('/api/v1/seller/balance')
            ->assertOk()
            ->assertJsonPath('data.balance_cents', 2700);

        $this->assertSame(3300, app(LedgerService::class)->platformBalanceCents());
    }

    public function test_balance_endpoints_enforce_authentication_and_role(): void
    {
        $this->getJson('/api/v1/seller/balance')->assertUnauthorized();
        $this->getJson('/api/v1/seller/ledger-entries')->assertUnauthorized();

        $buyer = $this->userWithRole(RoleName::BUYER);
        $this->withToken($this->accessToken($buyer))
            ->getJson('/api/v1/seller/balance')
            ->assertForbidden();

        $sellerWithoutStore = $this->userWithRole(RoleName::SELLER);
        $this->withToken($this->accessToken($sellerWithoutStore))
            ->getJson('/api/v1/seller/balance')
            ->assertNotFound();
    }

    public function test_ledger_entries_are_append_only(): void
    {
        $orderSeller = OrderSeller::factory()->withTotals(10000)->create([
            'status' => OrderSellerStatus::PAID,
        ]);
        $entry = app(LedgerService::class)->postSale($orderSeller)->first();

        try {
            $entry->update(['amount_cents' => 1]);
            $this->fail('Model update should be blocked.');
        } catch (LogicException) {
        }

        try {
            $entry->delete();
            $this->fail('Model delete should be blocked.');
        } catch (LogicException) {
        }

        $this->assertDatabaseMutationBlocked(
            fn () => DB::table('ledger_entries')->where('id', $entry->id)->update(['amount_cents' => 1]),
        );
        $this->assertDatabaseMutationBlocked(
            fn () => DB::table('ledger_entries')->where('id', $entry->id)->delete(),
        );

        $this->assertSame(9000, (int) LedgerEntry::query()->whereKey($entry->id)->value('amount_cents'));
    }

    public function test_seeder_creates_global_rule_only_once(): void
    {
        config(['payments.commission.default_percent_bps' => 1500]);

        $this->seed(PlatformFeeRuleSeeder::class);
        $this->seed(PlatformFeeRuleSeeder::class);

        $this->assertSame(1, PlatformFeeRule::query()->count());
        $this->assertSame(1500, PlatformFeeRule::query()->firstOrFail()->percent_bps);
    }

    private function assertDatabaseMutationBlocked(callable $mutation): void
    {
        try {
            DB::transaction($mutation);
            $this->fail('Database-level mutation should be blocked.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }
    }

    /**
     * @return array{int, int} [seller credit, platform commission]
     */
    private function amountsFor(Order $order, Store $store): array
    {
        $orderSellerId = OrderSeller::query()
            ->where('order_id', $order->id)
            ->where('store_id', $store->id)
            ->value('id');

        $entries = LedgerEntry::query()->where('order_seller_id', $orderSellerId)->get();

        return [
            (int) $entries->firstWhere('account_type', LedgerAccountType::SELLER)?->amount_cents,
            (int) $entries->firstWhere('account_type', LedgerAccountType::PLATFORM)?->amount_cents,
        ];
    }

    private function payOrder(string $token, Order $order): Payment
    {
        $externalId = $this->createPayment($token, $order)
            ->assertCreated()
            ->json('data.external_id');

        $this->sendWebhook('evt_'.Str::uuid(), $externalId)->assertAccepted();

        return Payment::query()->where('external_id', $externalId)->firstOrFail();
    }

    private function createPayment(string $token, Order $order): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->postJson(
            "/api/v1/orders/{$order->id}/payments",
            ['method' => PaymentMethod::PIX->value],
            ['Idempotency-Key' => 'payment-'.Str::uuid()],
        );
    }

    private function sendWebhook(string $eventId, string $externalId): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withoutToken()->postJson('/api/v1/webhooks/payments/mock', [
            'event_id' => $eventId,
            'type' => 'payment.paid',
            'data' => ['external_id' => $externalId],
        ], [
            'X-Mock-Webhook-Secret' => self::WEBHOOK_SECRET,
        ]);
    }

    /**
     * @param  array<string, int>  $offers
     */
    private function checkoutOrder(User $buyer, string $token, array $offers): Order
    {
        $address = Address::factory()->belongingToUser($buyer)->create();

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

    private function purchasableOffer(string $price = '99.90', int $stock = 10): Offer
    {
        $store = Store::factory()->active()->create();
        $product = Product::factory()->active()->create([
            'store_id' => $store->id,
        ]);

        return Offer::factory()
            ->active()
            ->withStock($stock, 0)
            ->priced($price)
            ->create([
                'store_id' => $store->id,
                'product_id' => $product->id,
            ]);
    }

    private function sellerOf(Store $store): User
    {
        $seller = $store->owner;
        $seller->roles()->syncWithoutDetaching([
            Role::query()->firstOrCreate(['name' => RoleName::SELLER])->id,
        ]);

        return $seller->fresh();
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
        $this->app['auth']->forgetGuards();

        return $user->createToken(
            'access_token',
            ['access'],
            now()->addHour(),
        )->plainTextToken;
    }
}
