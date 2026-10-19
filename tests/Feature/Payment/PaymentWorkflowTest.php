<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\OrderSellerStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentWebhookResult;
use App\Enums\ReservationStatus;
use App\Enums\RoleName;
use App\Models\Address;
use App\Models\InventoryReservation;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderSeller;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PaymentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'test-mock-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['payments.mock.webhook_secret' => self::WEBHOOK_SECRET]);
    }

    /**
     * @return array<string, array{PaymentMethod, list<string>}>
     */
    public static function methodProvider(): array
    {
        return [
            'pix' => [PaymentMethod::PIX, ['qr_code_text', 'expires_at']],
            'credit_card' => [PaymentMethod::CREDIT_CARD, ['checkout_url']],
            'boleto' => [PaymentMethod::BOLETO, ['digitable_line', 'barcode', 'due_date']],
        ];
    }

    public function test_guest_cannot_create_payment(): void
    {
        $order = Order::factory()->create();

        $this->postJson("/api/v1/orders/{$order->id}/payments", [
            'method' => PaymentMethod::PIX->value,
        ], ['Idempotency-Key' => 'payment-key-001'])->assertUnauthorized();
    }

    public function test_create_payment_validates_method_and_idempotency_key(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $token = $this->accessToken($buyer);
        $order = $this->checkoutOrder($buyer, $token);

        $this->withToken($token)
            ->postJson("/api/v1/orders/{$order->id}/payments", ['method' => 'bitcoin'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['method', 'Idempotency-Key']);
    }

    #[DataProvider('methodProvider')]
    public function test_buyer_creates_mock_payment_intent(PaymentMethod $method, array $instructionKeys): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $token = $this->accessToken($buyer);
        $offer = $this->purchasableOffer(price: '45.50', stock: 5);
        $order = $this->checkoutOrder($buyer, $token, [$offer->id => 2]);

        $response = $this->createPayment($token, $order, $method)
            ->assertCreated()
            ->assertJsonPath('data.order_id', $order->id)
            ->assertJsonPath('data.method', $method->value)
            ->assertJsonPath('data.status', PaymentStatus::PENDING->value)
            ->assertJsonPath('data.amount_cents', 9100)
            ->assertJsonPath('data.provider', 'mock');

        foreach ($instructionKeys as $key) {
            $this->assertNotEmpty($response->json("data.instructions.{$key}"));
        }

        $this->assertStringStartsWith('mock_pay_', $response->json('data.external_id'));
        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->fresh()->status);
    }

    public function test_create_payment_is_idempotent_per_key(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $token = $this->accessToken($buyer);
        $order = $this->checkoutOrder($buyer, $token);

        $firstId = $this->createPayment($token, $order, PaymentMethod::PIX, 'payment-key-replay')
            ->assertCreated()
            ->json('data.id');

        $this->createPayment($token, $order, PaymentMethod::PIX, 'payment-key-replay')
            ->assertOk()
            ->assertJsonPath('data.id', $firstId);

        $this->createPayment($token, $order, PaymentMethod::BOLETO, 'payment-key-replay')
            ->assertConflict();

        $this->assertSame(1, Payment::query()->where('order_id', $order->id)->count());
    }

    public function test_new_intent_supersedes_previous_pending_intent(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $token = $this->accessToken($buyer);
        $order = $this->checkoutOrder($buyer, $token);

        $pixId = $this->createPayment($token, $order, PaymentMethod::PIX)->json('data.id');
        $boletoId = $this->createPayment($token, $order, PaymentMethod::BOLETO)
            ->assertCreated()
            ->json('data.id');

        $this->assertSame(PaymentStatus::CANCELLED, Payment::query()->findOrFail($pixId)->status);
        $this->assertSame(PaymentStatus::PENDING, Payment::query()->findOrFail($boletoId)->status);

        $this->withToken($token)
            ->getJson("/api/v1/orders/{$order->id}/payments")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_buyer_cannot_pay_or_list_payments_of_another_buyers_order(): void
    {
        $owner = $this->userWithRole(RoleName::BUYER);
        $intruder = $this->userWithRole(RoleName::BUYER);
        $order = $this->checkoutOrder($owner, $this->accessToken($owner));

        $intruderToken = $this->accessToken($intruder);

        $this->createPayment($intruderToken, $order, PaymentMethod::PIX)->assertNotFound();

        $this->withToken($intruderToken)
            ->getJson("/api/v1/orders/{$order->id}/payments")
            ->assertNotFound();
    }

    public function test_cannot_create_payment_for_order_not_pending_payment(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $token = $this->accessToken($buyer);
        $order = $this->checkoutOrder($buyer, $token);

        $this->withToken($token)
            ->postJson("/api/v1/orders/{$order->id}/cancel")
            ->assertOk();

        $this->createPayment($token, $order, PaymentMethod::PIX)
            ->assertConflict()
            ->assertJsonPath('message', 'Somente pedidos em pending_payment podem receber pagamento.');
    }

    public function test_webhook_rejects_missing_or_invalid_secret(): void
    {
        $payment = Payment::factory()->create();
        $body = $this->webhookBody('evt_secret_check', 'payment.paid', $payment->external_id);

        $this->postJson('/api/v1/webhooks/payments/mock', $body)->assertUnauthorized();

        $this->postJson('/api/v1/webhooks/payments/mock', $body, [
            'X-Mock-Webhook-Secret' => 'wrong-secret',
        ])->assertUnauthorized();

        config(['payments.mock.webhook_secret' => '']);

        $this->postJson('/api/v1/webhooks/payments/mock', $body, [
            'X-Mock-Webhook-Secret' => '',
        ])->assertUnauthorized();

        $this->assertSame(0, PaymentWebhookEvent::query()->count());
    }

    public function test_webhook_validates_payload_and_unknown_payment(): void
    {
        $this->sendWebhook(['event_id' => 'evt_invalid', 'type' => 'payment.refunded'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type', 'data']);

        $this->sendWebhook($this->webhookBody('evt_unknown', 'payment.paid', 'mock_pay_does_not_exist'))
            ->assertNotFound();
    }

    #[DataProvider('methodProvider')]
    public function test_paid_webhook_marks_order_paid_and_commits_stock(PaymentMethod $method): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $token = $this->accessToken($buyer);
        $offerA = $this->purchasableOffer(price: '50.00', stock: 5);
        $offerB = $this->purchasableOffer(price: '30.00', stock: 4);
        $order = $this->checkoutOrder($buyer, $token, [
            $offerA->id => 2,
            $offerB->id => 1,
        ]);

        $externalId = $this->createPayment($token, $order, $method)->json('data.external_id');

        $this->sendWebhook($this->webhookBody('evt_paid_'.$method->value, 'payment.paid', $externalId))
            ->assertAccepted()
            ->assertJsonPath('data.processed', true)
            ->assertJsonPath('data.result', PaymentWebhookResult::APPLIED->value);

        $order->refresh();
        $this->assertSame(OrderStatus::PAID, $order->status);
        $this->assertSame(
            2,
            OrderSeller::query()
                ->where('order_id', $order->id)
                ->where('status', OrderSellerStatus::PAID)
                ->count(),
        );

        $payment = Payment::query()->where('external_id', $externalId)->firstOrFail();
        $this->assertSame(PaymentStatus::PAID, $payment->status);
        $this->assertNotNull($payment->paid_at);

        $offerA->refresh();
        $offerB->refresh();
        $this->assertSame([3, 0], [$offerA->stock, $offerA->reserved_stock]);
        $this->assertSame([3, 0], [$offerB->stock, $offerB->reserved_stock]);
        $this->assertSame(2, InventoryReservation::query()
            ->where('order_id', $order->id)
            ->where('status', ReservationStatus::COMMITTED)
            ->count());

        $histories = $this->withToken($token)
            ->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::PAID->value)
            ->json('data.status_histories');

        $this->assertContains(OrderStatus::PAID->value, array_column($histories, 'to_status'));
    }

    public function test_duplicate_webhook_is_a_safe_no_op(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $token = $this->accessToken($buyer);
        $offer = $this->purchasableOffer(stock: 5);
        $order = $this->checkoutOrder($buyer, $token, [$offer->id => 2]);
        $externalId = $this->createPayment($token, $order, PaymentMethod::PIX)->json('data.external_id');
        $body = $this->webhookBody('evt_duplicate', 'payment.paid', $externalId);

        $this->sendWebhook($body)->assertAccepted();

        $this->sendWebhook($body)
            ->assertOk()
            ->assertJsonPath('message', 'Evento já recebido anteriormente.')
            ->assertJsonPath('data.result', PaymentWebhookResult::APPLIED->value);

        $this->assertSame(1, PaymentWebhookEvent::query()->count());
        $this->assertSame(3, $offer->fresh()->stock);
        $this->assertSame(0, $offer->fresh()->reserved_stock);
        $this->assertSame(1, OrderStatusHistory::query()
            ->where('statusable_id', $order->id)
            ->where('to_status', OrderStatus::PAID->value)
            ->count());
    }

    public function test_new_paid_event_for_already_paid_payment_changes_nothing(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $token = $this->accessToken($buyer);
        $offer = $this->purchasableOffer(stock: 5);
        $order = $this->checkoutOrder($buyer, $token, [$offer->id => 1]);
        $externalId = $this->createPayment($token, $order, PaymentMethod::CREDIT_CARD)->json('data.external_id');

        $this->sendWebhook($this->webhookBody('evt_first', 'payment.paid', $externalId))->assertAccepted();

        $this->sendWebhook($this->webhookBody('evt_second', 'payment.paid', $externalId))
            ->assertAccepted()
            ->assertJsonPath('data.result', PaymentWebhookResult::ALREADY_PAID->value);

        $this->assertSame(4, $offer->fresh()->stock);
        $this->assertSame(OrderStatus::PAID, $order->fresh()->status);
    }

    public function test_event_id_reused_for_another_payment_is_rejected(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $token = $this->accessToken($buyer);
        $orderA = $this->checkoutOrder($buyer, $token);
        $orderB = $this->checkoutOrder($buyer, $token);
        $externalA = $this->createPayment($token, $orderA, PaymentMethod::PIX)->json('data.external_id');
        $externalB = $this->createPayment($token, $orderB, PaymentMethod::PIX)->json('data.external_id');

        $this->sendWebhook($this->webhookBody('evt_shared', 'payment.paid', $externalA))->assertAccepted();

        $this->sendWebhook($this->webhookBody('evt_shared', 'payment.paid', $externalB))
            ->assertConflict();

        $this->assertSame(OrderStatus::PENDING_PAYMENT, $orderB->fresh()->status);
    }

    public function test_failed_webhook_keeps_order_pending_and_allows_retry(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $token = $this->accessToken($buyer);
        $offer = $this->purchasableOffer(stock: 5);
        $order = $this->checkoutOrder($buyer, $token, [$offer->id => 1]);
        $externalId = $this->createPayment($token, $order, PaymentMethod::CREDIT_CARD)->json('data.external_id');

        $this->sendWebhook($this->webhookBody('evt_failed', 'payment.failed', $externalId))
            ->assertAccepted()
            ->assertJsonPath('data.result', PaymentWebhookResult::APPLIED->value);

        $this->assertSame(
            PaymentStatus::FAILED,
            Payment::query()->where('external_id', $externalId)->firstOrFail()->status,
        );
        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->fresh()->status);
        $this->assertSame(1, $offer->fresh()->reserved_stock);

        $retryExternalId = $this->createPayment($token, $order, PaymentMethod::PIX)
            ->assertCreated()
            ->json('data.external_id');

        $this->sendWebhook($this->webhookBody('evt_retry_paid', 'payment.paid', $retryExternalId))
            ->assertAccepted();

        $this->assertSame(OrderStatus::PAID, $order->fresh()->status);
        $this->assertSame(4, $offer->fresh()->stock);
    }

    public function test_cancelled_order_ignores_late_paid_webhook(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $token = $this->accessToken($buyer);
        $offer = $this->purchasableOffer(stock: 5);
        $order = $this->checkoutOrder($buyer, $token, [$offer->id => 2]);
        $externalId = $this->createPayment($token, $order, PaymentMethod::BOLETO)->json('data.external_id');

        $this->withToken($token)
            ->postJson("/api/v1/orders/{$order->id}/cancel")
            ->assertOk();

        $this->assertSame(
            PaymentStatus::CANCELLED,
            Payment::query()->where('external_id', $externalId)->firstOrFail()->status,
        );

        $this->sendWebhook($this->webhookBody('evt_late', 'payment.paid', $externalId))
            ->assertAccepted()
            ->assertJsonPath('data.result', PaymentWebhookResult::PAYMENT_NOT_PENDING->value);

        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
        $this->assertSame([5, 0], [$offer->fresh()->stock, $offer->fresh()->reserved_stock]);
    }

    public function test_payment_excludes_cancelled_sub_order_and_keeps_it_cancelled(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $token = $this->accessToken($buyer);
        $offerA = $this->purchasableOffer(price: '50.00', stock: 5);
        $offerB = $this->purchasableOffer(price: '30.00', stock: 5);
        $order = $this->checkoutOrder($buyer, $token, [
            $offerA->id => 1,
            $offerB->id => 1,
        ]);

        $staleExternalId = $this->createPayment($token, $order, PaymentMethod::PIX)->json('data.external_id');

        $orderSellerB = OrderSeller::query()
            ->where('order_id', $order->id)
            ->where('store_id', $offerB->store_id)
            ->firstOrFail();
        $sellerB = $offerB->store->owner;
        $sellerB->roles()->syncWithoutDetaching([
            Role::query()->firstOrCreate(['name' => RoleName::SELLER])->id,
        ]);

        $this->withToken($this->accessToken($sellerB))
            ->postJson("/api/v1/seller/order-sellers/{$orderSellerB->id}/cancel")
            ->assertOk();

        $this->assertSame(
            PaymentStatus::CANCELLED,
            Payment::query()->where('external_id', $staleExternalId)->firstOrFail()->status,
        );

        $buyerToken = $this->accessToken($buyer);
        $externalId = $this->createPayment($buyerToken, $order, PaymentMethod::PIX)
            ->assertCreated()
            ->assertJsonPath('data.amount_cents', 5000)
            ->json('data.external_id');

        $this->sendWebhook($this->webhookBody('evt_partial', 'payment.paid', $externalId))->assertAccepted();

        $this->assertSame(OrderStatus::PAID, $order->fresh()->status);
        $this->assertSame(OrderSellerStatus::CANCELLED, $orderSellerB->fresh()->status);
        $this->assertSame([4, 0], [$offerA->fresh()->stock, $offerA->fresh()->reserved_stock]);
        $this->assertSame([5, 0], [$offerB->fresh()->stock, $offerB->fresh()->reserved_stock]);
    }

    public function test_database_allows_only_one_open_payment_per_order(): void
    {
        $order = Order::factory()->create();
        Payment::factory()->forOrder($order)->create();
        Payment::factory()->forOrder($order)->create(['status' => PaymentStatus::FAILED]);

        $this->expectException(UniqueConstraintViolationException::class);

        Payment::factory()->forOrder($order)->paid()->create();
    }

    private function createPayment(
        string $token,
        Order $order,
        PaymentMethod $method,
        ?string $idempotencyKey = null,
    ): TestResponse {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->postJson(
            "/api/v1/orders/{$order->id}/payments",
            ['method' => $method->value],
            ['Idempotency-Key' => $idempotencyKey ?? 'payment-'.Str::uuid()],
        );
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function sendWebhook(array $body): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withoutToken()->postJson('/api/v1/webhooks/payments/mock', $body, [
            'X-Mock-Webhook-Secret' => self::WEBHOOK_SECRET,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function webhookBody(string $eventId, string $type, string $externalId): array
    {
        return [
            'event_id' => $eventId,
            'type' => $type,
            'data' => ['external_id' => $externalId],
        ];
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
        $this->app['auth']->forgetGuards();

        return $user->createToken(
            'access_token',
            ['access'],
            now()->addHour(),
        )->plainTextToken;
    }
}
