<?php

declare(strict_types=1);

namespace Tests\Feature\PostSale;

use App\Enums\DisputeStatus;
use App\Enums\OrderSellerStatus;
use App\Enums\OrderStatus;
use App\Enums\RoleName;
use App\Models\Dispute;
use App\Models\OrderSeller;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsPaidOrders;
use Tests\Concerns\ConfiguresShipping;
use Tests\TestCase;

final class DisputeWorkflowTest extends TestCase
{
    use BuildsPaidOrders;
    use ConfiguresShipping;
    use RefreshDatabase;

    // 99.90 sub-order, 10% commission: the seller is credited 99.90 - 9.99.
    private const SELLER_CREDIT_CENTS = 8991;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureMockPayments();
        $this->useFreeShipping();
    }

    public function test_open_dispute_holds_the_sub_order_balance(): void
    {
        $offer = $this->purchasableOffer();
        $buyer = $this->userWithRole(RoleName::BUYER);
        $subOrder = $this->deliveredSubOrder($buyer, $offer);
        $sellerToken = $this->accessToken($this->sellerOf($offer->store));

        $this->openDispute($buyer, $subOrder)
            ->assertCreated()
            ->assertJsonPath('data.status', DisputeStatus::OPEN->value)
            ->assertJsonPath('data.reason', 'not_as_described');

        $this->api($sellerToken)
            ->getJson('/api/v1/seller/balance')
            ->assertOk()
            ->assertJsonPath('data.balance_cents', self::SELLER_CREDIT_CENTS)
            ->assertJsonPath('data.held_cents', self::SELLER_CREDIT_CENTS)
            ->assertJsonPath('data.available_cents', 0);

        $this->api($sellerToken)
            ->postJson('/api/v1/seller/payouts', ['amount_cents' => 100], ['Idempotency-Key' => 'payout-'.Str::uuid()])
            ->assertConflict();

        $this->api($this->accessToken($buyer))
            ->getJson("/api/v1/orders/{$subOrder->order_id}")
            ->assertOk()
            ->assertJsonPath('data.sellers.0.dispute.status', DisputeStatus::OPEN->value);
    }

    public function test_dispute_requires_delivered_sub_order_within_window(): void
    {
        $offer = $this->purchasableOffer();
        $buyer = $this->userWithRole(RoleName::BUYER);

        $paid = $this->subOrderOf($this->paidOrder($buyer, [$offer->id => 1]), $offer);
        $this->openDispute($buyer, $paid)->assertConflict();

        $delivered = $this->deliveredSubOrder($buyer, $offer);
        $this->travel(8)->days();
        $this->openDispute($buyer, $delivered)
            ->assertConflict()
            ->assertJsonPath('message', 'O prazo de 7 dias após a entrega já terminou.');

        $this->assertDatabaseCount('disputes', 0);
    }

    public function test_only_the_buyer_opens_one_dispute_per_sub_order(): void
    {
        $offer = $this->purchasableOffer();
        $buyer = $this->userWithRole(RoleName::BUYER);
        $subOrder = $this->deliveredSubOrder($buyer, $offer);

        $this->openDispute($this->userWithRole(RoleName::BUYER), $subOrder)->assertNotFound();

        $this->asGuest()
            ->postJson("/api/v1/order-sellers/{$subOrder->id}/disputes", ['reason' => 'damaged', 'description' => 'Veio quebrado.'])
            ->assertUnauthorized();

        $this->api($this->accessToken($buyer))
            ->postJson("/api/v1/order-sellers/{$subOrder->id}/disputes", ['reason' => 'arrependimento', 'description' => 'curta'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason', 'description']);

        $this->openDispute($buyer, $subOrder)->assertCreated();
        $this->openDispute($buyer, $subOrder)->assertConflict();
    }

    public function test_seller_responds_and_admin_refunds_the_buyer(): void
    {
        $offer = $this->purchasableOffer();
        $buyer = $this->userWithRole(RoleName::BUYER);
        $subOrder = $this->deliveredSubOrder($buyer, $offer);
        $sellerToken = $this->accessToken($this->sellerOf($offer->store));
        $disputeId = $this->openDispute($buyer, $subOrder)->json('data.id');

        $this->api($this->accessToken($this->sellerOf($this->purchasableOffer()->store)))
            ->postJson("/api/v1/seller/disputes/{$disputeId}/respond", ['response' => 'Não é meu pedido.'])
            ->assertNotFound();

        $this->api($sellerToken)
            ->postJson("/api/v1/seller/disputes/{$disputeId}/respond", ['response' => 'Enviamos conforme o anúncio.'])
            ->assertOk()
            ->assertJsonPath('data.seller_response', 'Enviamos conforme o anúncio.');

        $this->api($sellerToken)
            ->postJson("/api/v1/admin/disputes/{$disputeId}/resolve", ['outcome' => 'reject', 'notes' => 'Tentativa do seller'])
            ->assertForbidden();

        $adminToken = $this->accessToken($this->userWithRole(RoleName::ADMIN));
        $this->api($adminToken)
            ->postJson("/api/v1/admin/disputes/{$disputeId}/resolve", ['outcome' => 'refund', 'notes' => 'Produto divergente do anúncio.'])
            ->assertOk()
            ->assertJsonPath('data.status', DisputeStatus::RESOLVED_REFUNDED->value);

        $refund = Refund::query()->where('order_seller_id', $subOrder->id)->sole();
        $this->assertSame(9990, $refund->amount_cents);
        $this->assertFalse($refund->restocked);
        $this->assertSame($refund->id, Dispute::query()->findOrFail($disputeId)->refund_id);
        $this->assertSame(OrderSellerStatus::REFUNDED, $subOrder->fresh()->status);
        $this->assertSame(OrderStatus::REFUNDED, $subOrder->order->fresh()->status);

        $this->api($sellerToken)
            ->getJson('/api/v1/seller/balance')
            ->assertJsonPath('data.balance_cents', 0)
            ->assertJsonPath('data.held_cents', 0);

        $this->api($adminToken)
            ->postJson("/api/v1/admin/disputes/{$disputeId}/resolve", ['outcome' => 'refund', 'notes' => 'De novo.'])
            ->assertConflict();
    }

    public function test_partial_refund_keeps_delivery_and_releases_the_rest(): void
    {
        $offer = $this->purchasableOffer();
        $buyer = $this->userWithRole(RoleName::BUYER);
        $subOrder = $this->deliveredSubOrder($buyer, $offer);
        $disputeId = $this->openDispute($buyer, $subOrder)->json('data.id');

        $this->api($this->accessToken($this->userWithRole(RoleName::ADMIN)))
            ->postJson("/api/v1/admin/disputes/{$disputeId}/resolve", [
                'outcome' => 'refund',
                'amount_cents' => 3000,
                'notes' => 'Compensação parcial por avaria leve.',
            ])
            ->assertOk();

        $this->assertSame(3000, (int) Refund::query()->where('order_seller_id', $subOrder->id)->sum('amount_cents'));
        $this->assertSame(OrderSellerStatus::DELIVERED, $subOrder->fresh()->status);

        $this->api($this->accessToken($this->sellerOf($offer->store)))
            ->getJson('/api/v1/seller/balance')
            ->assertJsonPath('data.held_cents', 0)
            ->assertJsonPath('data.available_cents', self::SELLER_CREDIT_CENTS - 2700);
    }

    public function test_rejected_dispute_releases_the_payout_hold(): void
    {
        $offer = $this->purchasableOffer();
        $buyer = $this->userWithRole(RoleName::BUYER);
        $subOrder = $this->deliveredSubOrder($buyer, $offer);
        $disputeId = $this->openDispute($buyer, $subOrder)->json('data.id');

        $this->api($this->accessToken($this->userWithRole(RoleName::ADMIN)))
            ->postJson("/api/v1/admin/disputes/{$disputeId}/resolve", ['outcome' => 'reject', 'amount_cents' => 100, 'notes' => 'x'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount_cents', 'notes']);

        $this->api($this->accessToken($this->userWithRole(RoleName::ADMIN)))
            ->postJson("/api/v1/admin/disputes/{$disputeId}/resolve", ['outcome' => 'reject', 'notes' => 'Rastreio confirma entrega.'])
            ->assertOk()
            ->assertJsonPath('data.status', DisputeStatus::RESOLVED_REJECTED->value)
            ->assertJsonPath('data.refund_id', null);

        $this->assertDatabaseCount('refunds', 0);
        $this->api($this->accessToken($this->sellerOf($offer->store)))
            ->getJson('/api/v1/seller/balance')
            ->assertJsonPath('data.held_cents', 0)
            ->assertJsonPath('data.available_cents', self::SELLER_CREDIT_CENTS);
    }

    public function test_listings_are_scoped(): void
    {
        $offerA = $this->purchasableOffer();
        $offerB = $this->purchasableOffer();
        $buyer = $this->userWithRole(RoleName::BUYER);
        $this->openDispute($buyer, $this->deliveredSubOrder($buyer, $offerA))->assertCreated();
        $this->openDispute($buyer, $this->deliveredSubOrder($buyer, $offerB))->assertCreated();

        $this->api($this->accessToken($this->sellerOf($offerA->store)))
            ->getJson('/api/v1/seller/disputes')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $adminToken = $this->accessToken($this->userWithRole(RoleName::ADMIN));
        $this->api($adminToken)->getJson('/api/v1/admin/disputes?status=open')->assertOk()->assertJsonCount(2, 'data');
        $this->api($adminToken)->getJson('/api/v1/admin/disputes?status=resolved_rejected')->assertOk()->assertJsonCount(0, 'data');

        $this->api($this->accessToken($buyer))->getJson('/api/v1/admin/disputes')->assertForbidden();
    }

    private function openDispute(User $buyer, OrderSeller $subOrder): TestResponse
    {
        return $this->api($this->accessToken($buyer))
            ->postJson("/api/v1/order-sellers/{$subOrder->id}/disputes", [
                'reason' => 'not_as_described',
                'description' => 'A cor do produto é diferente da anunciada.',
            ]);
    }
}
