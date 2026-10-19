<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LedgerAccountType;
use App\Enums\LedgerEntryReason;
use App\Enums\OrderSellerStatus;
use App\Enums\PayoutStatus;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\OrderSeller;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Refund;
use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class LedgerService
{
    public function __construct(
        private readonly PlatformFeeService $feeService,
    ) {}

    /**
     * Credits the seller (total − commission) and the platform (commission)
     * for a paid sub-order. Safe to call more than once.
     *
     * @return Collection<int, LedgerEntry>
     */
    public function postSale(OrderSeller $orderSeller, ?Payment $payment = null): Collection
    {
        return DB::transaction(function () use ($orderSeller, $payment): Collection {
            $orderSeller = OrderSeller::query()->lockForUpdate()->findOrFail($orderSeller->id);

            if ($orderSeller->status !== OrderSellerStatus::PAID) {
                throw new ConflictHttpException('Somente subpedidos pagos geram lançamentos de venda.');
            }

            $existing = $this->saleEntries($orderSeller->id);

            if ($existing->isNotEmpty()) {
                return $existing;
            }

            $breakdown = $this->feeService->calculate($orderSeller->subtotal_cents);
            $currency = (string) Order::query()->whereKey($orderSeller->order_id)->value('currency');
            $metadata = $breakdown->toMetadata();

            $sellerEntry = LedgerEntry::query()->create([
                'account_type' => LedgerAccountType::SELLER,
                'store_id' => $orderSeller->store_id,
                'order_seller_id' => $orderSeller->id,
                'payment_id' => $payment?->id,
                'platform_fee_rule_id' => $breakdown->ruleId,
                'reason' => LedgerEntryReason::SALE,
                'amount_cents' => $orderSeller->total_cents - $breakdown->feeCents,
                'currency' => $currency,
                'idempotency_key' => "sale:{$orderSeller->id}:seller",
                'metadata' => $metadata,
            ]);

            $platformEntry = LedgerEntry::query()->create([
                'account_type' => LedgerAccountType::PLATFORM,
                'store_id' => null,
                'order_seller_id' => $orderSeller->id,
                'payment_id' => $payment?->id,
                'platform_fee_rule_id' => $breakdown->ruleId,
                'reason' => LedgerEntryReason::COMMISSION,
                'amount_cents' => $breakdown->feeCents,
                'currency' => $currency,
                'idempotency_key' => "sale:{$orderSeller->id}:platform",
                'metadata' => $metadata,
            ]);

            return new Collection([$sellerEntry, $platformEntry]);
        });
    }

    /**
     * Reverses a refund: the platform returns its commission proportionally,
     * the seller covers the rest. The refund that closes the sub-order reverses
     * whatever is left, so totals never drift from rounding.
     *
     * Caller must hold locks on the order seller inside a transaction.
     *
     * @return Collection<int, LedgerEntry>
     */
    public function postRefund(Refund $refund): Collection
    {
        $existing = LedgerEntry::query()->where('refund_id', $refund->id)->get();
        if ($existing->isNotEmpty()) {
            return $existing;
        }

        $saleEntries = $this->saleEntries($refund->order_seller_id);
        $sellerSale = $saleEntries->firstWhere('reason', LedgerEntryReason::SALE);
        $commission = $saleEntries->firstWhere('reason', LedgerEntryReason::COMMISSION);

        if ($sellerSale === null || $commission === null) {
            throw new ConflictHttpException('Subpedido sem venda registrada no ledger.');
        }

        $grossCents = $sellerSale->amount_cents + $commission->amount_cents;
        $commissionAlreadyReversed = -1 * (int) LedgerEntry::query()
            ->where('order_seller_id', $refund->order_seller_id)
            ->where('reason', LedgerEntryReason::COMMISSION_REFUND)
            ->sum('amount_cents');
        $commissionLeft = $commission->amount_cents - $commissionAlreadyReversed;

        $refundedCents = (int) Refund::query()
            ->where('order_seller_id', $refund->order_seller_id)
            ->sum('amount_cents');

        $commissionRefund = $refundedCents >= $grossCents
            ? $commissionLeft
            : intdiv($commission->amount_cents * $refund->amount_cents + intdiv($grossCents, 2), $grossCents);
        $commissionRefund = max(0, min($commissionRefund, $commissionLeft));

        $metadata = [
            'refund_amount_cents' => $refund->amount_cents,
            'commission_refund_cents' => $commissionRefund,
            'gross_cents' => $grossCents,
        ];

        $sellerEntry = LedgerEntry::query()->create([
            'account_type' => LedgerAccountType::SELLER,
            'store_id' => $sellerSale->store_id,
            'order_seller_id' => $refund->order_seller_id,
            'payment_id' => $refund->payment_id,
            'refund_id' => $refund->id,
            'reason' => LedgerEntryReason::REFUND,
            'amount_cents' => -1 * ($refund->amount_cents - $commissionRefund),
            'currency' => $refund->currency,
            'idempotency_key' => "refund:{$refund->id}:seller",
            'metadata' => $metadata,
        ]);

        $platformEntry = LedgerEntry::query()->create([
            'account_type' => LedgerAccountType::PLATFORM,
            'store_id' => null,
            'order_seller_id' => $refund->order_seller_id,
            'payment_id' => $refund->payment_id,
            'refund_id' => $refund->id,
            'reason' => LedgerEntryReason::COMMISSION_REFUND,
            'amount_cents' => -1 * $commissionRefund,
            'currency' => $refund->currency,
            'idempotency_key' => "refund:{$refund->id}:platform",
            'metadata' => $metadata,
        ]);

        return new Collection([$sellerEntry, $platformEntry]);
    }

    public function postPayout(Payout $payout): LedgerEntry
    {
        return LedgerEntry::query()->firstOrCreate(
            ['idempotency_key' => "payout:{$payout->id}"],
            [
                'account_type' => LedgerAccountType::SELLER,
                'store_id' => $payout->store_id,
                'order_seller_id' => null,
                'payout_id' => $payout->id,
                'reason' => LedgerEntryReason::PAYOUT,
                'amount_cents' => -1 * $payout->amount_cents,
                'currency' => $payout->currency,
            ],
        );
    }

    public function hasSale(OrderSeller $orderSeller): bool
    {
        return $this->saleEntries($orderSeller->id)->isNotEmpty();
    }

    /**
     * Ledger balance minus payouts still awaiting settlement.
     */
    public function availableBalanceCents(Store $store): int
    {
        return $this->sellerBalanceCents($store) - $this->pendingPayoutCents($store);
    }

    public function pendingPayoutCents(Store $store): int
    {
        return (int) Payout::query()
            ->where('store_id', $store->id)
            ->where('status', PayoutStatus::REQUESTED)
            ->sum('amount_cents');
    }

    public function sellerBalanceCents(Store $store): int
    {
        return (int) LedgerEntry::query()
            ->where('account_type', LedgerAccountType::SELLER)
            ->where('store_id', $store->id)
            ->sum('amount_cents');
    }

    public function platformBalanceCents(): int
    {
        return (int) LedgerEntry::query()
            ->where('account_type', LedgerAccountType::PLATFORM)
            ->whereNull('store_id')
            ->sum('amount_cents');
    }

    /**
     * @return Builder<LedgerEntry>
     */
    public function sellerEntriesQuery(Store $store): Builder
    {
        return LedgerEntry::query()
            ->where('account_type', LedgerAccountType::SELLER)
            ->where('store_id', $store->id);
    }

    /**
     * @return Collection<int, LedgerEntry>
     */
    private function saleEntries(string $orderSellerId): Collection
    {
        return LedgerEntry::query()
            ->where('order_seller_id', $orderSellerId)
            ->whereIn('reason', [LedgerEntryReason::SALE, LedgerEntryReason::COMMISSION])
            ->get();
    }
}
