<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Enums\OrderSellerStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Enums\RoleName;
use App\Models\Address;
use App\Models\InventoryReservation;
use App\Models\LedgerEntry;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsPaidOrders;
use Tests\Concerns\ConfiguresShipping;
use Tests\TestCase;

final class UnpaidOrderExpirationTest extends TestCase
{
    use BuildsPaidOrders;
    use ConfiguresShipping;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureMockPayments();
        $this->useFreeShipping();
        config([
            'orders.unpaid_ttl_minutes' => 60,
            'payments.mock.pix_ttl_minutes' => 30,
            'payments.mock.boleto_due_days' => 3,
        ]);
        $this->freezeSecond();
    }

    public function test_overdue_unpaid_order_is_cancelled_and_stock_released(): void
    {
        $offer = $this->purchasableOffer(stock: 5);
        [$order, $buyer] = $this->unpaidOrder($offer, 2);

        $this->assertSame(now()->addMinutes(60)->toDateTimeString(), $order->payment_due_at->toDateTimeString());
        $this->assertSame(2, $offer->fresh()->reserved_stock);

        $this->travel(59)->minutes();
        $this->artisan('orders:expire-unpaid')->assertSuccessful();
        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->fresh()->status);

        $this->travel(2)->minutes();
        $this->artisan('orders:expire-unpaid')
            ->expectsOutput('1 pedido(s) expirado(s).')
            ->assertSuccessful();

        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
        $this->assertSame(OrderSellerStatus::CANCELLED, $order->sellers()->firstOrFail()->status);
        $this->assertSame(
            ReservationStatus::RELEASED,
            InventoryReservation::query()->where('order_id', $order->id)->sole()->status,
        );
        $this->assertSame(0, $offer->fresh()->reserved_stock);
        $this->assertSame(5, $offer->fresh()->stock);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'order.expired_unpaid',
            'auditable_id' => $order->id,
            'actor_id' => null,
        ]);
        $this->assertDatabaseHas('order_status_histories', [
            'statusable_id' => $order->id,
            'to_status' => OrderStatus::CANCELLED->value,
            'reason' => 'Prazo de pagamento expirado.',
            'actor_id' => null,
        ]);

        $this->api($this->accessToken($buyer))
            ->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::CANCELLED->value);
    }

    public function test_pix_attempt_extends_the_deadline_but_never_shortens_it(): void
    {
        $offer = $this->purchasableOffer();
        [$order, $buyer] = $this->unpaidOrder($offer);
        $token = $this->accessToken($buyer);

        $this->createPayment($token, $order, PaymentMethod::PIX);
        $this->assertSame(now()->addMinutes(60)->toDateTimeString(), $order->fresh()->payment_due_at->toDateTimeString());

        $this->travel(50)->minutes();
        $this->createPayment($token, $order, PaymentMethod::PIX)
            ->assertJsonPath('data.instructions.expires_at', now()->addMinutes(30)->toIso8601String());
        $this->assertSame(now()->addMinutes(30)->toDateTimeString(), $order->fresh()->payment_due_at->toDateTimeString());

        $this->travel(20)->minutes();
        $this->artisan('orders:expire-unpaid');
        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->fresh()->status);

        $this->travel(11)->minutes();
        $this->artisan('orders:expire-unpaid');
        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
        $this->assertSame(
            0,
            Payment::query()->where('order_id', $order->id)->where('status', PaymentStatus::PENDING)->count(),
        );
    }

    public function test_boleto_keeps_the_order_until_its_due_date(): void
    {
        $offer = $this->purchasableOffer();
        [$order, $buyer] = $this->unpaidOrder($offer);

        $this->createPayment($this->accessToken($buyer), $order, PaymentMethod::BOLETO);
        $this->assertSame(now()->addDays(3)->endOfDay()->toDateTimeString(), $order->fresh()->payment_due_at->toDateTimeString());

        $this->travel(2)->days();
        $this->artisan('orders:expire-unpaid');
        $this->assertSame(OrderStatus::PENDING_PAYMENT, $order->fresh()->status);

        $this->travel(2)->days();
        $this->artisan('orders:expire-unpaid');
        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
    }

    public function test_late_payment_on_expired_order_is_ignored(): void
    {
        $offer = $this->purchasableOffer(stock: 3);
        [$order, $buyer] = $this->unpaidOrder($offer);
        $externalId = $this->createPayment($this->accessToken($buyer), $order, PaymentMethod::PIX)
            ->json('data.external_id');

        $this->travel(61)->minutes();
        $this->artisan('orders:expire-unpaid');

        $this->asGuest()
            ->postJson('/api/v1/webhooks/payments/mock', [
                'event_id' => 'evt_'.Str::uuid(),
                'type' => 'payment.paid',
                'data' => ['external_id' => $externalId],
            ], ['X-Mock-Webhook-Secret' => $this->mockWebhookSecret])
            ->assertAccepted();

        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
        $this->assertSame(PaymentStatus::CANCELLED, Payment::query()->where('external_id', $externalId)->sole()->status);
        $this->assertSame(0, LedgerEntry::query()->count());
        $this->assertSame(3, $offer->fresh()->stock);
    }

    public function test_paid_orders_are_never_expired(): void
    {
        $offer = $this->purchasableOffer();
        $order = $this->paidOrder($this->userWithRole(RoleName::BUYER), [$offer->id => 1]);

        $this->travel(2)->days();
        $this->artisan('orders:expire-unpaid')->expectsOutput('0 pedido(s) expirado(s).');

        $this->assertSame(OrderStatus::PAID, $order->fresh()->status);
    }

    public function test_reservation_sweep_skips_order_reservations(): void
    {
        $offer = $this->purchasableOffer(stock: 5);
        $standaloneBuyer = $this->userWithRole(RoleName::BUYER);
        $this->api($this->accessToken($standaloneBuyer))
            ->postJson("/api/v1/offers/{$offer->id}/reservations", ['quantity' => 1])
            ->assertCreated();

        [$order, $buyer] = $this->unpaidOrder($offer, 2);
        $this->assertSame(3, $offer->fresh()->reserved_stock);

        $this->travel(20)->minutes();
        $this->artisan('reservations:release-expired')
            ->expectsOutput('1 reserva(s) liberada(s).')
            ->assertSuccessful();

        $this->assertSame(2, $offer->fresh()->reserved_stock);
        $this->assertSame(
            ReservationStatus::ACTIVE,
            InventoryReservation::query()->where('order_id', $order->id)->sole()->status,
        );

        $externalId = $this->createPayment($this->accessToken($buyer), $order, PaymentMethod::PIX)
            ->json('data.external_id');
        $this->asGuest()
            ->postJson('/api/v1/webhooks/payments/mock', [
                'event_id' => 'evt_'.Str::uuid(),
                'type' => 'payment.paid',
                'data' => ['external_id' => $externalId],
            ], ['X-Mock-Webhook-Secret' => $this->mockWebhookSecret])
            ->assertAccepted();

        $this->assertSame(OrderStatus::PAID, $order->fresh()->status);
        $this->assertSame(3, $offer->fresh()->stock);
        $this->assertSame(0, $offer->fresh()->reserved_stock);
    }

    /**
     * @return array{Order, User}
     */
    private function unpaidOrder(Offer $offer, int $quantity = 1): array
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $token = $this->accessToken($buyer);
        $address = Address::factory()->belongingToUser($buyer)->create();

        $this->api($token)
            ->postJson('/api/v1/cart/items', ['offer_id' => $offer->id, 'quantity' => $quantity])
            ->assertCreated();

        $orderId = $this->api($token)
            ->postJson('/api/v1/checkout', ['address_id' => $address->id], [
                'Idempotency-Key' => 'unpaid-'.Str::uuid(),
            ])
            ->assertCreated()
            ->json('data.id');

        return [Order::query()->findOrFail($orderId), $buyer];
    }

    private function createPayment(string $token, Order $order, PaymentMethod $method): TestResponse
    {
        return $this->api($token)
            ->postJson("/api/v1/orders/{$order->id}/payments", ['method' => $method->value], [
                'Idempotency-Key' => 'payment-'.Str::uuid(),
            ])
            ->assertCreated();
    }
}
