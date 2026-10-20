<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\PaymentMethod;
use App\Enums\RoleName;
use App\Events\OrderPlaced;
use App\Models\Address;
use App\Models\Offer;
use App\Models\Order;
use App\Models\User;
use App\Notifications\NewSaleNotification;
use App\Notifications\OrderCancelledNotification;
use App\Notifications\OrderPaidNotification;
use App\Notifications\OrderPlacedNotification;
use App\Notifications\OrderSellerCancelledNotification;
use App\Notifications\OrderSellerDeliveredNotification;
use App\Notifications\OrderSellerShippedNotification;
use App\Notifications\PaymentInstructionsNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\BuildsPaidOrders;
use Tests\Concerns\ConfiguresShipping;
use Tests\TestCase;

final class OrderNotificationsTest extends TestCase
{
    use BuildsPaidOrders;
    use ConfiguresShipping;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureMockPayments();
        $this->useFreeShipping();
        Notification::fake();
    }

    public function test_paid_multi_seller_order_notifies_buyer_and_each_seller(): void
    {
        $offerA = $this->purchasableOffer('50.00');
        $offerB = $this->purchasableOffer('30.00');
        $buyer = $this->userWithRole(RoleName::BUYER);

        $order = $this->paidOrder($buyer, [$offerA->id => 1, $offerB->id => 2]);

        Notification::assertSentToTimes($buyer, OrderPlacedNotification::class, 1);
        Notification::assertSentTo($buyer, PaymentInstructionsNotification::class, fn (PaymentInstructionsNotification $n): bool => $n->payment->method === PaymentMethod::PIX);
        Notification::assertSentTo($buyer, OrderPaidNotification::class, fn (OrderPaidNotification $n): bool => $n->order->is($order));

        foreach ([$offerA, $offerB] as $offer) {
            Notification::assertSentToTimes($offer->store->owner, NewSaleNotification::class, 1);
            Notification::assertSentTo(
                $offer->store->owner,
                NewSaleNotification::class,
                fn (NewSaleNotification $n): bool => $n->orderSeller->is($this->subOrderOf($order, $offer)),
            );
        }
    }

    public function test_shipping_and_delivery_notify_the_buyer(): void
    {
        $offer = $this->purchasableOffer();
        $buyer = $this->userWithRole(RoleName::BUYER);

        $subOrder = $this->deliveredSubOrder($buyer, $offer);

        Notification::assertSentTo($buyer, OrderSellerShippedNotification::class, function (OrderSellerShippedNotification $n) use ($buyer, $subOrder): bool {
            return $n->orderSeller->is($subOrder)
                && $n->toArray($buyer)['data']['tracking_code'] === $subOrder->shipment->tracking_code;
        });
        Notification::assertSentTo($buyer, OrderSellerDeliveredNotification::class);
    }

    public function test_checkout_and_payment_replays_do_not_notify_twice(): void
    {
        $offer = $this->purchasableOffer();
        $buyer = $this->userWithRole(RoleName::BUYER);
        $token = $this->accessToken($buyer);
        $address = Address::factory()->belongingToUser($buyer)->create();

        $this->api($token)->postJson('/api/v1/cart/items', ['offer_id' => $offer->id, 'quantity' => 1])->assertCreated();

        $orderId = null;
        foreach ([201, 200] as $status) {
            $orderId = $this->api($token)
                ->postJson('/api/v1/checkout', ['address_id' => $address->id], ['Idempotency-Key' => 'checkout-replay-1'])
                ->assertStatus($status)
                ->json('data.id');
        }

        foreach ([201, 200] as $status) {
            $this->api($token)
                ->postJson("/api/v1/orders/{$orderId}/payments", ['method' => 'boleto'], ['Idempotency-Key' => 'payment-replay-1'])
                ->assertStatus($status);
        }

        Notification::assertSentToTimes($buyer, OrderPlacedNotification::class, 1);
        Notification::assertSentToTimes($buyer, PaymentInstructionsNotification::class, 1);
    }

    public function test_duplicate_payment_webhook_notifies_once(): void
    {
        $offer = $this->purchasableOffer();
        $buyer = $this->userWithRole(RoleName::BUYER);
        $order = $this->paidOrder($buyer, [$offer->id => 1]);
        $payment = $this->paymentOf($order);
        $event = ['event_id' => 'evt_'.Str::uuid(), 'type' => 'payment.paid', 'data' => ['external_id' => $payment->external_id]];

        $this->asGuest()
            ->postJson('/api/v1/webhooks/payments/mock', $event, ['X-Mock-Webhook-Secret' => $this->mockWebhookSecret])
            ->assertSuccessful();

        Notification::assertSentToTimes($buyer, OrderPaidNotification::class, 1);
    }

    public function test_buyer_cancellation_and_expiration_notify_with_their_cause(): void
    {
        $offer = $this->purchasableOffer(stock: 5);
        $buyer = $this->userWithRole(RoleName::BUYER);

        $cancelled = $this->placeOrder($buyer, $offer, 'checkout-cancel');
        $this->api($this->accessToken($buyer))->postJson("/api/v1/orders/{$cancelled->id}/cancel")->assertOk();

        $expired = $this->placeOrder($buyer, $offer, 'checkout-expire');
        $this->travel(2)->hours();
        $this->artisan('orders:expire-unpaid')->assertSuccessful();

        Notification::assertSentTo($buyer, OrderCancelledNotification::class, fn (OrderCancelledNotification $n): bool => $n->order->is($cancelled) && $n->cause === OrderCancelledNotification::CAUSE_BUYER);
        Notification::assertSentTo($buyer, OrderCancelledNotification::class, fn (OrderCancelledNotification $n): bool => $n->order->is($expired) && $n->cause === OrderCancelledNotification::CAUSE_EXPIRED);
    }

    public function test_seller_cancellation_notifies_the_buyer(): void
    {
        $offer = $this->purchasableOffer();
        $buyer = $this->userWithRole(RoleName::BUYER);
        $subOrder = $this->subOrderOf($this->paidOrder($buyer, [$offer->id => 1]), $offer);

        $this->api($this->accessToken($this->sellerOf($offer->store)))
            ->postJson("/api/v1/seller/order-sellers/{$subOrder->id}/cancel", ['reason' => 'Sem estoque físico.'])
            ->assertOk();

        Notification::assertSentTo($buyer, OrderSellerCancelledNotification::class, function (OrderSellerCancelledNotification $n) use ($buyer): bool {
            return $n->toArray($buyer)['data']['refunded_cents'] === 9990;
        });
    }

    public function test_events_from_a_rolled_back_transaction_are_not_dispatched(): void
    {
        $order = Order::factory()->create();

        try {
            DB::transaction(function () use ($order): void {
                event(new OrderPlaced($order));

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        Notification::assertNothingSent();
    }

    private function placeOrder(User $buyer, Offer $offer, string $key): Order
    {
        $token = $this->accessToken($buyer);
        $address = Address::factory()->belongingToUser($buyer)->create();

        $this->api($token)->postJson('/api/v1/cart/items', ['offer_id' => $offer->id, 'quantity' => 1])->assertCreated();
        $orderId = $this->api($token)
            ->postJson('/api/v1/checkout', ['address_id' => $address->id], ['Idempotency-Key' => $key])
            ->assertCreated()
            ->json('data.id');

        return Order::query()->findOrFail($orderId);
    }
}
