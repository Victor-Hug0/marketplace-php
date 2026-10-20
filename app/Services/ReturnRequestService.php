<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ReturnRequestStatus;
use App\Events\ReturnRequestDecided;
use App\Events\ReturnRequested;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderSeller;
use App\Models\ReturnRequest;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ReturnRequestService
{
    public function __construct(
        private readonly PostSaleEligibility $eligibility,
        private readonly OrderService $orderService,
        private readonly RefundService $refundService,
    ) {}

    /**
     * Null amount asks for everything still refundable on the sub-order.
     */
    public function request(User $buyer, OrderSeller $orderSeller, string $reason, ?int $amountCents): ReturnRequest
    {
        try {
            return DB::transaction(function () use ($buyer, $orderSeller, $reason, $amountCents): ReturnRequest {
                $orderSeller = OrderSeller::query()->lockForUpdate()->findOrFail($orderSeller->id);

                if (ReturnRequest::query()->where('order_seller_id', $orderSeller->id)->exists()) {
                    throw new ConflictHttpException('Este subpedido já teve uma devolução solicitada.');
                }

                $this->eligibility->assertBuyerCanOpen(
                    $buyer,
                    $orderSeller,
                    (int) config('post_sale.return_window_days'),
                );

                $remaining = $this->refundService->remainingCents($orderSeller);
                $amountCents ??= $remaining;

                if ($amountCents < 1 || $amountCents > $remaining) {
                    throw new ConflictHttpException(
                        "O valor da devolução deve estar entre 1 e {$remaining} centavos.",
                    );
                }

                $returnRequest = ReturnRequest::query()->create([
                    'order_seller_id' => $orderSeller->id,
                    'requested_by' => $buyer->id,
                    'status' => ReturnRequestStatus::REQUESTED,
                    'reason' => $reason,
                    'amount_cents' => $amountCents,
                ]);

                event(new ReturnRequested($returnRequest));
                $this->audit($buyer, $returnRequest, 'return_request.created', ['amount_cents' => $amountCents]);

                return $returnRequest;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ConflictHttpException('Este subpedido já teve uma devolução solicitada.');
        }
    }

    public function approve(User $actor, ReturnRequest $returnRequest, ?string $notes): ReturnRequest
    {
        return DB::transaction(function () use ($actor, $returnRequest, $notes): ReturnRequest {
            $returnRequest = $this->lockPending($returnRequest);

            $refund = $this->orderService->issueRefund(
                $actor,
                $returnRequest->orderSeller,
                $returnRequest->amount_cents,
                'Devolução aprovada: '.$returnRequest->reason,
                "return:{$returnRequest->id}",
                'order_seller.refunded_by_return',
            )['refund'];

            $returnRequest->update([
                'status' => ReturnRequestStatus::APPROVED,
                'decision_notes' => $notes,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'refund_id' => $refund->id,
            ]);

            event(new ReturnRequestDecided($returnRequest));
            $this->audit($actor, $returnRequest, 'return_request.approved', [
                'refund_id' => $refund->id,
                'amount_cents' => $refund->amount_cents,
            ]);

            return $returnRequest->refresh();
        });
    }

    public function reject(User $actor, ReturnRequest $returnRequest, string $notes): ReturnRequest
    {
        return DB::transaction(function () use ($actor, $returnRequest, $notes): ReturnRequest {
            $returnRequest = $this->lockPending($returnRequest);

            $returnRequest->update([
                'status' => ReturnRequestStatus::REJECTED,
                'decision_notes' => $notes,
                'decided_by' => $actor->id,
                'decided_at' => now(),
            ]);

            event(new ReturnRequestDecided($returnRequest));
            $this->audit($actor, $returnRequest, 'return_request.rejected');

            return $returnRequest->refresh();
        });
    }

    private function lockPending(ReturnRequest $returnRequest): ReturnRequest
    {
        Order::query()->lockForUpdate()->findOrFail(
            OrderSeller::query()->whereKey($returnRequest->order_seller_id)->value('order_id'),
        );
        $orderSeller = OrderSeller::query()->lockForUpdate()->findOrFail($returnRequest->order_seller_id);
        $returnRequest = ReturnRequest::query()->lockForUpdate()->findOrFail($returnRequest->id);

        if (! $returnRequest->isPending()) {
            throw new ConflictHttpException('A devolução já foi decidida.');
        }

        return $returnRequest->setRelation('orderSeller', $orderSeller);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function audit(User $actor, ReturnRequest $returnRequest, string $action, array $payload = []): void
    {
        AuditLog::query()->create([
            'action' => $action,
            'actor_id' => $actor->id,
            'actor_type' => $actor->getMorphClass(),
            'auditable_id' => $returnRequest->id,
            'auditable_type' => $returnRequest->getMorphClass(),
            'payload' => ['order_seller_id' => $returnRequest->order_seller_id, ...$payload],
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
        ]);
    }
}
