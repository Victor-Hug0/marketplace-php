<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\RoleName;
use App\Models\User;
use App\Notifications\PayoutProcessedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsPaidOrders;
use Tests\Concerns\ConfiguresShipping;
use Tests\TestCase;

final class PayoutNotificationsTest extends TestCase
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

    public function test_paid_and_failed_payouts_notify_the_store_owner(): void
    {
        $offer = $this->purchasableOffer();
        $this->paidOrder($this->userWithRole(RoleName::BUYER), [$offer->id => 1]);
        $seller = $this->sellerOf($offer->store);
        $admin = $this->userWithRole(RoleName::ADMIN);

        $paid = $this->requestPayout($seller, 1000);
        $this->api($this->accessToken($admin))->postJson("/api/v1/admin/payouts/{$paid}/mark-paid")->assertOk();

        $failed = $this->requestPayout($seller, 500);
        $this->api($this->accessToken($admin))
            ->postJson("/api/v1/admin/payouts/{$failed}/mark-failed", ['reason' => 'Conta bancária encerrada.'])
            ->assertOk();

        Notification::assertSentTo($seller, PayoutProcessedNotification::class, fn (PayoutProcessedNotification $n): bool => $n->payout->id === $paid && $n->type() === 'payout.paid');
        Notification::assertSentTo($seller, PayoutProcessedNotification::class, fn (PayoutProcessedNotification $n): bool => $n->payout->id === $failed && $n->type() === 'payout.failed');
        Notification::assertSentToTimes($seller, PayoutProcessedNotification::class, 2);
    }

    private function requestPayout(User $seller, int $amountCents): string
    {
        return $this->api($this->accessToken($seller))
            ->postJson('/api/v1/seller/payouts', ['amount_cents' => $amountCents], ['Idempotency-Key' => 'payout-'.Str::uuid()])
            ->assertCreated()
            ->json('data.id');
    }
}
