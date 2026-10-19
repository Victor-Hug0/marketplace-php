<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Enums\PaymentMethod;
use App\Enums\RoleName;
use App\Models\Address;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderSeller;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Str;

trait BuildsPaidOrders
{
    protected string $mockWebhookSecret = 'test-mock-webhook-secret';

    protected function configureMockPayments(int $commissionBps = 1000): void
    {
        config([
            'payments.mock.webhook_secret' => $this->mockWebhookSecret,
            'payments.commission.default_percent_bps' => $commissionBps,
        ]);
    }

    /**
     * @param  array<string, int>  $offers  offer id => quantity
     */
    protected function paidOrder(User $buyer, array $offers): Order
    {
        $token = $this->accessToken($buyer);
        $address = Address::factory()->belongingToUser($buyer)->create();

        foreach ($offers as $offerId => $quantity) {
            $this->withToken($token)
                ->postJson('/api/v1/cart/items', ['offer_id' => $offerId, 'quantity' => $quantity])
                ->assertCreated();
        }

        $orderId = $this->withToken($token)
            ->postJson('/api/v1/checkout', ['address_id' => $address->id], [
                'Idempotency-Key' => 'order-flow-'.Str::uuid(),
            ])
            ->assertCreated()
            ->json('data.id');

        $externalId = $this->withToken($token)
            ->postJson("/api/v1/orders/{$orderId}/payments", ['method' => PaymentMethod::PIX->value], [
                'Idempotency-Key' => 'payment-'.Str::uuid(),
            ])
            ->assertCreated()
            ->json('data.external_id');

        $this->app['auth']->forgetGuards();

        $this->withoutToken()
            ->postJson('/api/v1/webhooks/payments/mock', [
                'event_id' => 'evt_'.Str::uuid(),
                'type' => 'payment.paid',
                'data' => ['external_id' => $externalId],
            ], ['X-Mock-Webhook-Secret' => $this->mockWebhookSecret])
            ->assertAccepted();

        return Order::query()->findOrFail($orderId);
    }

    protected function paymentOf(Order $order): Payment
    {
        return Payment::query()->where('order_id', $order->id)->latest()->firstOrFail();
    }

    protected function subOrderOf(Order $order, Offer $offer): OrderSeller
    {
        return OrderSeller::query()
            ->where('order_id', $order->id)
            ->where('store_id', $offer->store_id)
            ->firstOrFail();
    }

    protected function purchasableOffer(string $price = '99.90', int $stock = 10): Offer
    {
        $store = Store::factory()->active()->create();
        $product = Product::factory()->active()->create(['store_id' => $store->id]);

        return Offer::factory()
            ->active()
            ->withStock($stock, 0)
            ->priced($price)
            ->create([
                'store_id' => $store->id,
                'product_id' => $product->id,
            ]);
    }

    protected function sellerOf(Store $store): User
    {
        $seller = $store->owner;
        $seller->roles()->syncWithoutDetaching([
            Role::query()->firstOrCreate(['name' => RoleName::SELLER])->id,
        ]);

        return $seller->fresh();
    }

    protected function userWithRole(RoleName $role): User
    {
        $user = User::factory()->create();
        $user->roles()->syncWithoutDetaching([
            Role::query()->firstOrCreate(['name' => $role])->id,
        ]);

        return $user->fresh();
    }

    protected function api(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    protected function asGuest(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withoutToken();
    }

    protected function accessToken(User $user): string
    {
        $this->app['auth']->forgetGuards();

        return $user->createToken('access_token', ['access'], now()->addHour())->plainTextToken;
    }
}
