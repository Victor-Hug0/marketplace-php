<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\RoleName;
use App\Models\Dispute;
use App\Models\ReturnRequest;
use App\Notifications\DisputeOpenedNotification;
use App\Notifications\DisputeResolvedNotification;
use App\Notifications\ReturnRequestDecidedNotification;
use App\Notifications\ReturnRequestedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsPaidOrders;
use Tests\Concerns\ConfiguresShipping;
use Tests\TestCase;

final class PostSaleNotificationsTest extends TestCase
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

    public function test_dispute_notifies_seller_and_admins_then_both_parties_on_resolution(): void
    {
        $offer = $this->purchasableOffer();
        $buyer = $this->userWithRole(RoleName::BUYER);
        $seller = $this->sellerOf($offer->store);
        $admins = [$this->userWithRole(RoleName::ADMIN), $this->userWithRole(RoleName::ADMIN)];
        $subOrder = $this->deliveredSubOrder($buyer, $offer);

        $this->api($this->accessToken($buyer))
            ->postJson("/api/v1/order-sellers/{$subOrder->id}/disputes", ['reason' => 'damaged', 'description' => 'Chegou com a tela trincada.'])
            ->assertCreated();
        $dispute = Dispute::query()->firstOrFail();

        Notification::assertSentTo($seller, DisputeOpenedNotification::class, fn (DisputeOpenedNotification $n): bool => $n->audience === DisputeOpenedNotification::FOR_SELLER);
        foreach ($admins as $admin) {
            Notification::assertSentTo($admin, DisputeOpenedNotification::class, fn (DisputeOpenedNotification $n): bool => $n->audience === DisputeOpenedNotification::FOR_ADMIN);
        }
        Notification::assertNotSentTo($buyer, DisputeOpenedNotification::class);

        $this->api($this->accessToken($admins[0]))
            ->postJson("/api/v1/admin/disputes/{$dispute->id}/resolve", ['outcome' => 'refund', 'notes' => 'Foto comprova o dano.'])
            ->assertOk();

        Notification::assertSentTo($buyer, DisputeResolvedNotification::class, function (DisputeResolvedNotification $n) use ($buyer): bool {
            return $n->audience === DisputeResolvedNotification::FOR_BUYER
                && $n->toArray($buyer)['data']['refund_cents'] === 9990;
        });
        Notification::assertSentTo($seller, DisputeResolvedNotification::class, fn (DisputeResolvedNotification $n): bool => $n->audience === DisputeResolvedNotification::FOR_SELLER);
    }

    public function test_return_request_notifies_seller_and_decision_notifies_buyer(): void
    {
        $offer = $this->purchasableOffer();
        $buyer = $this->userWithRole(RoleName::BUYER);
        $seller = $this->sellerOf($offer->store);
        $subOrder = $this->deliveredSubOrder($buyer, $offer);

        $this->api($this->accessToken($buyer))
            ->postJson("/api/v1/order-sellers/{$subOrder->id}/returns", ['reason' => 'Não serviu, produto lacrado.'])
            ->assertCreated();
        $returnRequest = ReturnRequest::query()->firstOrFail();

        Notification::assertSentTo($seller, ReturnRequestedNotification::class);

        $this->api($this->accessToken($seller))
            ->postJson("/api/v1/seller/returns/{$returnRequest->id}/reject", ['notes' => 'Prazo de devolução expirado.'])
            ->assertOk();

        Notification::assertSentTo($buyer, ReturnRequestDecidedNotification::class, function (ReturnRequestDecidedNotification $n) use ($buyer): bool {
            return $n->toArray($buyer)['data']['status'] === 'rejected';
        });
    }
}
