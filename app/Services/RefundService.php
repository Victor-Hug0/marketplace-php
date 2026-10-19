<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\OrderItem;
use App\Models\OrderSeller;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class RefundService
{
    public function __construct(
        private readonly LedgerService $ledgerService,
        private readonly InventoryService $inventoryService,
    ) {}

    public function refundedCents(OrderSeller $orderSeller): int
    {
        return (int) Refund::query()
            ->where('order_seller_id', $orderSeller->id)
            ->sum('amount_cents');
    }

    public function remainingCents(OrderSeller $orderSeller): int
    {
        return max(0, $orderSeller->total_cents - $this->refundedCents($orderSeller));
    }

    public function findByIdempotencyKey(OrderSeller $orderSeller, string $idempotencyKey): ?Refund
    {
        return Refund::query()
            ->where('order_seller_id', $orderSeller->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    public function paidPaymentFor(OrderSeller $orderSeller): Payment
    {
        $payment = Payment::query()
            ->where('order_id', $orderSeller->order_id)
            ->where('status', PaymentStatus::PAID)
            ->lockForUpdate()
            ->first();

        if ($payment === null || ! $this->ledgerService->hasSale($orderSeller)) {
            throw new ConflictHttpException('O subpedido não possui pagamento confirmado para reembolso.');
        }

        return $payment;
    }

    /**
     * Mock gateway refund: settles immediately and posts the ledger reversal.
     * Caller must hold locks on the order and order seller inside a transaction.
     */
    public function issue(
        OrderSeller $orderSeller,
        Payment $payment,
        int $amountCents,
        ?User $actor,
        string $reason,
        string $idempotencyKey,
        bool $restockOnFullRefund,
    ): Refund {
        $remaining = $this->remainingCents($orderSeller);

        if ($amountCents < 1 || $amountCents > $remaining) {
            throw new ConflictHttpException(
                "O valor do reembolso deve estar entre 1 e {$remaining} centavos.",
            );
        }

        $closesSubOrder = $amountCents === $remaining;
        $restock = $closesSubOrder && $restockOnFullRefund;

        $refund = Refund::query()->create([
            'payment_id' => $payment->id,
            'order_seller_id' => $orderSeller->id,
            'actor_id' => $actor?->id,
            'external_id' => 'mock_ref_'.Str::lower((string) Str::ulid()),
            'idempotency_key' => $idempotencyKey,
            'amount_cents' => $amountCents,
            'currency' => $payment->currency,
            'reason' => $reason,
            'restocked' => $restock,
            'processed_at' => now(),
        ]);

        $this->ledgerService->postRefund($refund);

        if ($restock) {
            OrderItem::query()
                ->where('order_seller_id', $orderSeller->id)
                ->whereNotNull('offer_id')
                ->orderBy('offer_id')
                ->get(['offer_id', 'quantity'])
                ->each(fn (OrderItem $item) => $this->inventoryService->restock(
                    $item->offer_id,
                    $item->quantity,
                ));
        }

        return $refund;
    }
}
