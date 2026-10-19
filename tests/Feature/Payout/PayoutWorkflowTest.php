<?php

declare(strict_types=1);

namespace Tests\Feature\Payout;

use App\Enums\LedgerEntryReason;
use App\Enums\PaymentStatus;
use App\Enums\PayoutStatus;
use App\Enums\RoleName;
use App\Enums\StoreStatus;
use App\Models\LedgerEntry;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Refund;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsPaidOrders;
use Tests\TestCase;

final class PayoutWorkflowTest extends TestCase
{
    use BuildsPaidOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureMockPayments();
    }

    public function test_seller_requests_payout_and_funds_are_reserved(): void
    {
        [$seller] = $this->sellerWithSale('100.00');
        $token = $this->accessToken($seller);

        $this->requestPayout($token, 4000)
            ->assertCreated()
            ->assertJsonPath('data.status', PayoutStatus::REQUESTED->value)
            ->assertJsonPath('data.amount_cents', 4000);

        $this->api($token)
            ->getJson('/api/v1/seller/balance')
            ->assertOk()
            ->assertJsonPath('data.balance_cents', 9000)
            ->assertJsonPath('data.pending_payout_cents', 4000)
            ->assertJsonPath('data.available_cents', 5000);

        $this->requestPayout($token, 5001)
            ->assertConflict()
            ->assertJsonPath('message', 'Saldo disponível insuficiente para o saque.');

        $this->requestPayout($token, 5000)->assertCreated();
        $this->requestPayout($token, 1)->assertConflict();

        $this->api($token)
            ->getJson('/api/v1/seller/payouts')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_payout_request_is_idempotent_per_key(): void
    {
        [$seller] = $this->sellerWithSale('100.00');
        $token = $this->accessToken($seller);

        $payoutId = $this->requestPayout($token, 1000, 'payout-key-001')->assertCreated()->json('data.id');

        $this->requestPayout($token, 1000, 'payout-key-001')
            ->assertOk()
            ->assertJsonPath('data.id', $payoutId);

        $this->requestPayout($token, 2000, 'payout-key-001')->assertConflict();

        $this->assertSame(1, Payout::query()->count());
    }

    public function test_admin_marks_payout_paid_and_ledger_is_debited(): void
    {
        [$seller] = $this->sellerWithSale('100.00');
        $sellerToken = $this->accessToken($seller);
        $payoutId = $this->requestPayout($sellerToken, 6000)->json('data.id');
        $adminToken = $this->accessToken($this->userWithRole(RoleName::ADMIN));

        $this->api($adminToken)
            ->postJson("/api/v1/admin/payouts/{$payoutId}/mark-paid")
            ->assertOk()
            ->assertJsonPath('data.status', PayoutStatus::PAID->value);

        $entry = LedgerEntry::query()->where('payout_id', $payoutId)->sole();
        $this->assertSame(LedgerEntryReason::PAYOUT, $entry->reason);
        $this->assertSame(-6000, $entry->amount_cents);

        $this->api($this->accessToken($seller))
            ->getJson('/api/v1/seller/balance')
            ->assertJsonPath('data.balance_cents', 3000)
            ->assertJsonPath('data.pending_payout_cents', 0)
            ->assertJsonPath('data.available_cents', 3000);

        $this->api($adminToken)
            ->postJson("/api/v1/admin/payouts/{$payoutId}/mark-paid")
            ->assertConflict();

        $this->api($adminToken)
            ->postJson("/api/v1/admin/payouts/{$payoutId}/mark-failed", ['reason' => 'Tarde demais'])
            ->assertConflict();

        $this->assertSame(1, LedgerEntry::query()->where('payout_id', $payoutId)->count());
    }

    public function test_failed_payout_releases_funds_without_ledger_entry(): void
    {
        [$seller] = $this->sellerWithSale('100.00');
        $payoutId = $this->requestPayout($this->accessToken($seller), 9000)->json('data.id');
        $adminToken = $this->accessToken($this->userWithRole(RoleName::ADMIN));

        $this->api($adminToken)
            ->postJson("/api/v1/admin/payouts/{$payoutId}/mark-failed")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);

        $this->api($adminToken)
            ->postJson("/api/v1/admin/payouts/{$payoutId}/mark-failed", ['reason' => 'Conta bancária inválida'])
            ->assertOk()
            ->assertJsonPath('data.status', PayoutStatus::FAILED->value)
            ->assertJsonPath('data.failure_reason', 'Conta bancária inválida');

        $this->assertSame(0, LedgerEntry::query()->whereNotNull('payout_id')->count());

        $this->api($this->accessToken($seller))
            ->getJson('/api/v1/seller/balance')
            ->assertJsonPath('data.available_cents', 9000);
    }

    public function test_admin_lists_payouts_filtered_by_status(): void
    {
        [$sellerA] = $this->sellerWithSale('100.00');
        [$sellerB] = $this->sellerWithSale('50.00');
        $paidId = $this->requestPayout($this->accessToken($sellerA), 1000)->json('data.id');
        $this->requestPayout($this->accessToken($sellerB), 1000)->assertCreated();
        $adminToken = $this->accessToken($this->userWithRole(RoleName::ADMIN));

        $this->api($adminToken)->postJson("/api/v1/admin/payouts/{$paidId}/mark-paid")->assertOk();

        $this->api($adminToken)
            ->getJson('/api/v1/admin/payouts')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->api($adminToken)
            ->getJson('/api/v1/admin/payouts?status=requested')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', PayoutStatus::REQUESTED->value);
    }

    public function test_inactive_store_cannot_request_payout(): void
    {
        [$seller, $store] = $this->sellerWithSale('100.00');
        $store->update(['status' => StoreStatus::SUSPENDED]);

        $this->requestPayout($this->accessToken($seller), 1000)
            ->assertConflict()
            ->assertJsonPath('message', 'Somente lojas ativas podem solicitar saque.');
    }

    public function test_payout_endpoints_enforce_roles_and_ownership(): void
    {
        [$sellerA] = $this->sellerWithSale('100.00');
        [$sellerB] = $this->sellerWithSale('100.00');
        $payoutId = $this->requestPayout($this->accessToken($sellerA), 1000)->json('data.id');

        $this->asGuest()->getJson('/api/v1/seller/payouts')->assertUnauthorized();

        $buyerToken = $this->accessToken($this->userWithRole(RoleName::BUYER));
        $this->requestPayout($buyerToken, 1000)->assertForbidden();

        $sellerBToken = $this->accessToken($sellerB);
        $this->api($sellerBToken)->getJson('/api/v1/seller/payouts')->assertOk()->assertJsonCount(0, 'data');
        $this->api($sellerBToken)->getJson('/api/v1/admin/payouts')->assertForbidden();
        $this->api($sellerBToken)->postJson("/api/v1/admin/payouts/{$payoutId}/mark-paid")->assertForbidden();

        $sellerWithoutStore = $this->accessToken($this->userWithRole(RoleName::SELLER));
        $this->requestPayout($sellerWithoutStore, 1000)->assertNotFound();

        $this->api($sellerBToken)
            ->postJson('/api/v1/seller/payouts', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount_cents', 'Idempotency-Key']);
    }

    public function test_refund_after_payout_can_leave_negative_balance_and_blocks_new_payouts(): void
    {
        [$seller, , $order, $offer] = $this->sellerWithSale('100.00');
        $payoutId = $this->requestPayout($this->accessToken($seller), 9000)->json('data.id');
        $admin = $this->userWithRole(RoleName::ADMIN);
        $adminToken = $this->accessToken($admin);

        $this->api($adminToken)->postJson("/api/v1/admin/payouts/{$payoutId}/mark-paid")->assertOk();

        $this->api($adminToken)
            ->postJson("/api/v1/admin/order-sellers/{$this->subOrderOf($order, $offer)->id}/refunds", [
                'reason' => 'Reembolso integral',
            ], ['Idempotency-Key' => 'refund-'.Str::uuid()])
            ->assertCreated();

        $sellerToken = $this->accessToken($seller);
        $this->api($sellerToken)
            ->getJson('/api/v1/seller/balance')
            ->assertJsonPath('data.balance_cents', -9000)
            ->assertJsonPath('data.available_cents', -9000);

        $this->requestPayout($sellerToken, 1)->assertConflict();
    }

    public function test_financial_totals_reconcile_across_sales_refunds_and_payouts(): void
    {
        $buyer = $this->userWithRole(RoleName::BUYER);
        $offerA = $this->purchasableOffer(price: '123.45', stock: 10);
        $offerB = $this->purchasableOffer(price: '67.89', stock: 10);
        $orderOne = $this->paidOrder($buyer, [$offerA->id => 3, $offerB->id => 2]);
        $orderTwo = $this->paidOrder($buyer, [$offerB->id => 1]);

        $adminToken = $this->accessToken($this->userWithRole(RoleName::ADMIN));
        $this->api($adminToken)
            ->postJson("/api/v1/admin/order-sellers/{$this->subOrderOf($orderOne, $offerA)->id}/refunds", [
                'amount_cents' => 4321,
                'reason' => 'Parcial',
            ], ['Idempotency-Key' => 'refund-'.Str::uuid()])
            ->assertCreated();
        $this->api($adminToken)
            ->postJson("/api/v1/admin/order-sellers/{$this->subOrderOf($orderTwo, $offerB)->id}/refunds", [
                'reason' => 'Total',
            ], ['Idempotency-Key' => 'refund-'.Str::uuid()])
            ->assertCreated();

        $sellerA = $this->sellerOf($offerA->store);
        $payoutId = $this->requestPayout($this->accessToken($sellerA), 10000)->json('data.id');
        $this->api($adminToken)->postJson("/api/v1/admin/payouts/{$payoutId}/mark-paid")->assertOk();

        $captured = (int) Payment::query()->where('status', PaymentStatus::PAID)->sum('amount_cents');
        $refunded = (int) Refund::query()->sum('amount_cents');
        $paidOut = (int) Payout::query()->where('status', PayoutStatus::PAID)->sum('amount_cents');

        $this->assertSame(37035 + 13578 + 6789, $captured);
        $this->assertSame($captured - $refunded - $paidOut, (int) LedgerEntry::query()->sum('amount_cents'));

        $platformCommission = (int) LedgerEntry::query()
            ->whereIn('reason', [LedgerEntryReason::COMMISSION, LedgerEntryReason::COMMISSION_REFUND])
            ->sum('amount_cents');
        $sellerNet = (int) LedgerEntry::query()
            ->whereIn('reason', [LedgerEntryReason::SALE, LedgerEntryReason::REFUND])
            ->sum('amount_cents');

        $this->assertSame($captured - $refunded, $platformCommission + $sellerNet);
        $this->assertGreaterThan(0, $platformCommission);
    }

    /**
     * @return array{User, Store, Order, Offer}
     */
    private function sellerWithSale(string $price): array
    {
        $offer = $this->purchasableOffer(price: $price);
        $order = $this->paidOrder($this->userWithRole(RoleName::BUYER), [$offer->id => 1]);

        return [$this->sellerOf($offer->store), $offer->store, $order, $offer];
    }

    private function requestPayout(string $token, int $amountCents, ?string $idempotencyKey = null): TestResponse
    {
        return $this->api($token)->postJson('/api/v1/seller/payouts', [
            'amount_cents' => $amountCents,
        ], [
            'Idempotency-Key' => $idempotencyKey ?? 'payout-'.Str::uuid(),
        ]);
    }
}
