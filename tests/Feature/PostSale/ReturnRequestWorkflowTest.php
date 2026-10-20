<?php

declare(strict_types=1);

namespace Tests\Feature\PostSale;

use App\Enums\OrderSellerStatus;
use App\Enums\ReturnRequestStatus;
use App\Enums\RoleName;
use App\Models\OrderSeller;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsPaidOrders;
use Tests\Concerns\ConfiguresShipping;
use Tests\TestCase;

final class ReturnRequestWorkflowTest extends TestCase
{
    use BuildsPaidOrders;
    use ConfiguresShipping;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureMockPayments();
        $this->useFreeShipping();
    }

    public function test_seller_approves_full_return_and_buyer_is_refunded(): void
    {
        $offer = $this->purchasableOffer(stock: 5);
        $buyer = $this->userWithRole(RoleName::BUYER);
        $subOrder = $this->deliveredSubOrder($buyer, $offer);
        $sellerToken = $this->accessToken($this->sellerOf($offer->store));

        $returnId = $this->requestReturn($buyer, $subOrder)
            ->assertCreated()
            ->assertJsonPath('data.status', ReturnRequestStatus::REQUESTED->value)
            ->assertJsonPath('data.amount_cents', 9990)
            ->json('data.id');

        $this->api($sellerToken)
            ->getJson('/api/v1/seller/balance')
            ->assertJsonPath('data.held_cents', 8991)
            ->assertJsonPath('data.available_cents', 0);

        $this->api($sellerToken)
            ->postJson("/api/v1/seller/returns/{$returnId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', ReturnRequestStatus::APPROVED->value);

        $refund = Refund::query()->where('order_seller_id', $subOrder->id)->sole();
        $this->assertSame(9990, $refund->amount_cents);
        $this->assertSame(OrderSellerStatus::REFUNDED, $subOrder->fresh()->status);
        $this->assertSame(4, $offer->fresh()->stock);

        $this->api($sellerToken)
            ->postJson("/api/v1/seller/returns/{$returnId}/approve")
            ->assertConflict();
    }

    public function test_partial_return_and_rejection_need_notes(): void
    {
        $offer = $this->purchasableOffer();
        $buyer = $this->userWithRole(RoleName::BUYER);
        $subOrder = $this->deliveredSubOrder($buyer, $offer);
        $sellerToken = $this->accessToken($this->sellerOf($offer->store));

        $this->requestReturn($buyer, $subOrder, 10000)->assertConflict();

        $returnId = $this->requestReturn($buyer, $subOrder, 2000)
            ->assertCreated()
            ->assertJsonPath('data.amount_cents', 2000)
            ->json('data.id');

        $this->api($sellerToken)
            ->postJson("/api/v1/seller/returns/{$returnId}/reject")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['notes']);

        $this->api($sellerToken)
            ->postJson("/api/v1/seller/returns/{$returnId}/reject", ['notes' => 'Produto foi usado.'])
            ->assertOk()
            ->assertJsonPath('data.status', ReturnRequestStatus::REJECTED->value);

        $this->assertDatabaseCount('refunds', 0);
        $this->api($sellerToken)
            ->getJson('/api/v1/seller/balance')
            ->assertJsonPath('data.held_cents', 0)
            ->assertJsonPath('data.available_cents', 8991);
    }

    public function test_admin_can_decide_and_other_sellers_cannot(): void
    {
        $offer = $this->purchasableOffer();
        $buyer = $this->userWithRole(RoleName::BUYER);
        $subOrder = $this->deliveredSubOrder($buyer, $offer);
        $returnId = $this->requestReturn($buyer, $subOrder, 2500)->json('data.id');

        $this->api($this->accessToken($this->sellerOf($this->purchasableOffer()->store)))
            ->postJson("/api/v1/seller/returns/{$returnId}/approve")
            ->assertNotFound();

        $this->api($this->accessToken($buyer))
            ->postJson("/api/v1/admin/returns/{$returnId}/approve")
            ->assertForbidden();

        $this->api($this->accessToken($this->userWithRole(RoleName::ADMIN)))
            ->postJson("/api/v1/admin/returns/{$returnId}/approve", ['notes' => 'Seller não respondeu.'])
            ->assertOk()
            ->assertJsonPath('data.status', ReturnRequestStatus::APPROVED->value);

        $this->assertSame(2500, (int) Refund::query()->where('order_seller_id', $subOrder->id)->sum('amount_cents'));
        $this->assertSame(OrderSellerStatus::DELIVERED, $subOrder->fresh()->status);
    }

    public function test_return_and_dispute_cannot_run_at_the_same_time(): void
    {
        $offer = $this->purchasableOffer();
        $buyer = $this->userWithRole(RoleName::BUYER);
        $subOrder = $this->deliveredSubOrder($buyer, $offer);

        $this->requestReturn($buyer, $subOrder)->assertCreated();

        $this->api($this->accessToken($buyer))
            ->postJson("/api/v1/order-sellers/{$subOrder->id}/disputes", [
                'reason' => 'damaged',
                'description' => 'Chegou com a caixa amassada.',
            ])
            ->assertConflict();

        $this->requestReturn($buyer, $subOrder)->assertConflict();
    }

    public function test_return_requires_buyer_and_delivery(): void
    {
        $offer = $this->purchasableOffer();
        $buyer = $this->userWithRole(RoleName::BUYER);

        $paid = $this->subOrderOf($this->paidOrder($buyer, [$offer->id => 1]), $offer);
        $this->requestReturn($buyer, $paid)->assertConflict();

        $delivered = $this->deliveredSubOrder($buyer, $offer);
        $this->requestReturn($this->userWithRole(RoleName::BUYER), $delivered)->assertNotFound();

        $this->api($this->accessToken($buyer))
            ->postJson("/api/v1/order-sellers/{$delivered->id}/returns", ['reason' => 'curto'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);
    }

    private function requestReturn(User $buyer, OrderSeller $subOrder, ?int $amountCents = null): TestResponse
    {
        return $this->api($this->accessToken($buyer))
            ->postJson("/api/v1/order-sellers/{$subOrder->id}/returns", array_filter([
                'reason' => 'Não serviu, quero devolver o produto.',
                'amount_cents' => $amountCents,
            ]));
    }
}
