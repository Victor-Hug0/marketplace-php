<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LedgerAccountType;
use App\Enums\LedgerEntryReason;
use App\Enums\OrderSellerStatus;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\OrderSeller;
use App\Models\Payment;
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

            $existing = LedgerEntry::query()
                ->where('order_seller_id', $orderSeller->id)
                ->whereIn('reason', [LedgerEntryReason::SALE, LedgerEntryReason::COMMISSION])
                ->get();

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
}
