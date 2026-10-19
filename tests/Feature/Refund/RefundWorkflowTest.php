<?php

declare(strict_types=1);

namespace Tests\Feature\Refund;

use App\Enums\LedgerAccountType;
use App\Enums\LedgerEntryReason;
use App\Enums\OrderSellerStatus;
use App\Enums\OrderStatus;
use App\Enums\RoleName;
use App\Models\LedgerEntry;
use App\Models\OrderSeller;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsPaidOrders;
use Tests\TestCase;

final class RefundWorkflowTest extends TestCase
{
    use BuildsPaidOrders;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureMockPayments();
        $this->admin = $this->userWithRole(RoleName::ADMIN);
    }

    public function test_admin_partial_refund_reverses_ledger_proportionally_without_restock(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $offer = $this->purchasableOffer(price: '100.00', stock: 5);
        $order = $this->paidOrder($buyer, [$offer->id => 1]);
        $subOrder = $this->subOrderOf($order, $offer);

        $this->refund($subOrder, ['amount_cents' => 2500, 'reason' => 'Item com avaria leve'])
            ->assertCreated()
            ->assertJsonPath('data.amount_cents', 2500)
            ->assertJsonPath('data.restocked', false);

        $this->assertSame([-2250, -250], $this->refundAmounts($subOrder));
        $this->assertSame(OrderSellerStatus::PAID, $subOrder->fresh()->status);
        $this->assertSame(OrderStatus::PAID, $order->fresh()->status);
        $this->assertSame(4, $offer->fresh()->stock);

        $this->api($this->accessToken($buyer))
            ->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.sellers.0.refunded_cents', 2500)
            ->assertJsonCount(1, 'data.sellers.0.refunds');
    }

    public function test_closing_refund_zeroes_ledger_restocks_and_marks_refunded(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $offer = $this->purchasableOffer(price: '10.00', stock: 5);
        $order = $this->paidOrder($buyer, [$offer->id => 1]);
        $subOrder = $this->subOrderOf($order, $offer);

        $this->refund($subOrder, ['amount_cents' => 333, 'reason' => 'Parcial 1'])->assertCreated();
        $this->refund($subOrder, ['amount_cents' => 333, 'reason' => 'Parcial 2'])->assertCreated();
        $this->refund($subOrder, ['reason' => 'Restante'])
            ->assertCreated()
            ->assertJsonPath('data.amount_cents', 334)
            ->assertJsonPath('data.restocked', true);

        $entries = LedgerEntry::query()->where('order_seller_id', $subOrder->id)->get();
        $this->assertSame(0, (int) $entries->where('account_type', LedgerAccountType::SELLER)->sum('amount_cents'));
        $this->assertSame(0, (int) $entries->where('account_type', LedgerAccountType::PLATFORM)->sum('amount_cents'));
        $this->assertSame(
            [-33, -33, -34],
            $entries->where('reason', LedgerEntryReason::COMMISSION_REFUND)
                ->sortBy('id')
                ->pluck('amount_cents')
                ->values()
                ->all(),
        );

        $this->assertSame(OrderSellerStatus::REFUNDED, $subOrder->fresh()->status);
        $this->assertSame(OrderStatus::REFUNDED, $order->fresh()->status);
        $this->assertSame(5, $offer->fresh()->stock);

        $this->refund($subOrder, ['amount_cents' => 1, 'reason' => 'Excedente'])->assertConflict();
    }

    public function test_refund_of_one_seller_keeps_order_paid_until_all_are_closed(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $offerA = $this->purchasableOffer(price: '50.00');
        $offerB = $this->purchasableOffer(price: '30.00');
        $order = $this->paidOrder($buyer, [$offerA->id => 1, $offerB->id => 1]);

        $this->refund($this->subOrderOf($order, $offerB), ['reason' => 'Produto indisponível'])->assertCreated();

        $this->assertSame(OrderSellerStatus::REFUNDED, $this->subOrderOf($order, $offerB)->status);
        $this->assertSame(OrderSellerStatus::PAID, $this->subOrderOf($order, $offerA)->status);
        $this->assertSame(OrderStatus::PAID, $order->fresh()->status);

        $this->refund($this->subOrderOf($order, $offerA), ['reason' => 'Cliente desistiu'])->assertCreated();

        $this->assertSame(OrderStatus::REFUNDED, $order->fresh()->status);
        $this->assertSame(8000, (int) Refund::query()->sum('amount_cents'));
    }

    public function test_refund_after_shipping_keeps_status_and_does_not_restock(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $offer = $this->purchasableOffer(price: '40.00', stock: 3);
        $order = $this->paidOrder($buyer, [$offer->id => 1]);
        $subOrder = $this->subOrderOf($order, $offer);
        $subOrder->update(['status' => OrderSellerStatus::SHIPPED]);

        $this->refund($subOrder, ['reason' => 'Extravio na entrega'])
            ->assertCreated()
            ->assertJsonPath('data.restocked', false);

        $this->assertSame(OrderSellerStatus::SHIPPED, $subOrder->fresh()->status);
        $this->assertSame(2, $offer->fresh()->stock);
    }

    public function test_refund_is_idempotent_per_key(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $offer = $this->purchasableOffer(price: '100.00');
        $subOrder = $this->subOrderOf($this->paidOrder($buyer, [$offer->id => 1]), $offer);

        $refundId = $this->refund($subOrder, ['amount_cents' => 1000, 'reason' => 'Ajuste'], 'refund-key-001')
            ->assertCreated()
            ->json('data.id');

        $this->refund($subOrder, ['amount_cents' => 1000, 'reason' => 'Ajuste'], 'refund-key-001')
            ->assertOk()
            ->assertJsonPath('data.id', $refundId);

        $this->refund($subOrder, ['amount_cents' => 2000, 'reason' => 'Ajuste'], 'refund-key-001')
            ->assertConflict();

        $this->assertSame(1, Refund::query()->count());
        $this->assertSame(2, LedgerEntry::query()->where('refund_id', $refundId)->count());
    }

    public function test_refund_rejects_unpaid_sub_order_and_invalid_amounts(): void
    {
        $unpaid = OrderSeller::factory()->create();

        $this->refund($unpaid, ['reason' => 'Sem pagamento'])->assertConflict();

        $buyer = $this->userWithRole(RoleName::BUYER);
        $offer = $this->purchasableOffer(price: '20.00');
        $subOrder = $this->subOrderOf($this->paidOrder($buyer, [$offer->id => 1]), $offer);

        $this->refund($subOrder, ['amount_cents' => 2001, 'reason' => 'Acima do total'])->assertConflict();

        $this->refund($subOrder, ['amount_cents' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount_cents', 'reason']);

        $this->assertSame(0, Refund::query()->count());
    }

    public function test_only_admin_can_refund(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $offer = $this->purchasableOffer();
        $subOrder = $this->subOrderOf($this->paidOrder($buyer, [$offer->id => 1]), $offer);

        $this->asGuest()
            ->postJson("/api/v1/admin/order-sellers/{$subOrder->id}/refunds", ['reason' => 'x'])
            ->assertUnauthorized();

        foreach ([$buyer, $this->sellerOf($offer->store)] as $user) {
            $this->api($this->accessToken($user))
                ->postJson("/api/v1/admin/order-sellers/{$subOrder->id}/refunds", ['reason' => 'x'], [
                    'Idempotency-Key' => 'refund-'.Str::uuid(),
                ])
                ->assertForbidden();
        }
    }

    public function test_seller_cancelling_paid_sub_order_refunds_remaining_and_restocks(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $offerA = $this->purchasableOffer(price: '50.00', stock: 5);
        $offerB = $this->purchasableOffer(price: '30.00', stock: 5);
        $order = $this->paidOrder($buyer, [$offerA->id => 2, $offerB->id => 1]);
        $subOrderA = $this->subOrderOf($order, $offerA);

        $this->refund($subOrderA, ['amount_cents' => 1000, 'reason' => 'Desconto'])->assertCreated();

        $this->api($this->accessToken($this->sellerOf($offerA->store)))
            ->postJson("/api/v1/seller/order-sellers/{$subOrderA->id}/cancel", ['reason' => 'Sem estoque físico'])
            ->assertOk()
            ->assertJsonPath('data.status', OrderSellerStatus::CANCELLED->value)
            ->assertJsonPath('data.refunded_cents', 10000)
            ->assertJsonCount(2, 'data.refunds');

        $this->assertSame(5, $offerA->fresh()->stock);
        $this->assertSame(4, $offerB->fresh()->stock);
        $this->assertSame(0, (int) LedgerEntry::query()
            ->where('order_seller_id', $subOrderA->id)
            ->sum('amount_cents'));
        $this->assertSame(OrderStatus::PAID, $order->fresh()->status);
    }

    public function test_seller_cancelling_only_paid_sub_order_cancels_order_with_full_refund(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $offer = $this->purchasableOffer(price: '25.00', stock: 4);
        $order = $this->paidOrder($buyer, [$offer->id => 2]);
        $subOrder = $this->subOrderOf($order, $offer);

        $this->api($this->accessToken($this->sellerOf($offer->store)))
            ->postJson("/api/v1/seller/order-sellers/{$subOrder->id}/cancel")
            ->assertOk();

        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
        $this->assertSame(5000, (int) Refund::query()->where('order_seller_id', $subOrder->id)->sum('amount_cents'));
        $this->assertSame(4, $offer->fresh()->stock);
        $this->assertSame(0, (int) LedgerEntry::query()->sum('amount_cents'));
    }

    /**
     * @return array{int, int} [seller reversal, platform reversal]
     */
    private function refundAmounts(OrderSeller $subOrder): array
    {
        $entries = LedgerEntry::query()
            ->where('order_seller_id', $subOrder->id)
            ->whereNotNull('refund_id')
            ->get();

        return [
            (int) $entries->where('reason', LedgerEntryReason::REFUND)->sum('amount_cents'),
            (int) $entries->where('reason', LedgerEntryReason::COMMISSION_REFUND)->sum('amount_cents'),
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function refund(OrderSeller $subOrder, array $body, ?string $idempotencyKey = null): TestResponse
    {
        return $this->api($this->accessToken($this->admin))
            ->postJson("/api/v1/admin/order-sellers/{$subOrder->id}/refunds", $body, [
                'Idempotency-Key' => $idempotencyKey ?? 'refund-'.Str::uuid(),
            ]);
    }
}
