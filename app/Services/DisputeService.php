<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DisputeReason;
use App\Enums\DisputeStatus;
use App\Events\DisputeOpened;
use App\Events\DisputeResolved;
use App\Models\AuditLog;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\OrderSeller;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class DisputeService
{
    public function __construct(
        private readonly PostSaleEligibility $eligibility,
        private readonly OrderService $orderService,
    ) {}

    public function open(User $buyer, OrderSeller $orderSeller, DisputeReason $reason, string $description): Dispute
    {
        try {
            return DB::transaction(function () use ($buyer, $orderSeller, $reason, $description): Dispute {
                $orderSeller = OrderSeller::query()->lockForUpdate()->findOrFail($orderSeller->id);

                if (Dispute::query()->where('order_seller_id', $orderSeller->id)->exists()) {
                    throw new ConflictHttpException('Este subpedido já teve uma disputa aberta.');
                }

                $this->eligibility->assertBuyerCanOpen(
                    $buyer,
                    $orderSeller,
                    (int) config('post_sale.dispute_window_days'),
                );

                $dispute = Dispute::query()->create([
                    'order_seller_id' => $orderSeller->id,
                    'opened_by' => $buyer->id,
                    'status' => DisputeStatus::OPEN,
                    'reason' => $reason,
                    'description' => $description,
                ]);

                event(new DisputeOpened($dispute));
                $this->audit($buyer, $dispute, 'dispute.opened', ['reason' => $reason->value]);

                return $dispute;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ConflictHttpException('Este subpedido já teve uma disputa aberta.');
        }
    }

    public function respond(User $seller, Dispute $dispute, string $response): Dispute
    {
        return DB::transaction(function () use ($seller, $dispute, $response): Dispute {
            $dispute = $this->lockedOpen($dispute);

            $dispute->update([
                'seller_response' => $response,
                'seller_responded_at' => now(),
            ]);

            $this->audit($seller, $dispute, 'dispute.seller_responded');

            return $dispute->refresh();
        });
    }

    /**
     * Admin decision: refund the buyer (null amount = everything left) or
     * reject the dispute. Either way the seller's money stops being held.
     */
    public function resolve(User $admin, Dispute $dispute, bool $refundBuyer, ?int $amountCents, string $notes): Dispute
    {
        return DB::transaction(function () use ($admin, $dispute, $refundBuyer, $amountCents, $notes): Dispute {
            Order::query()->lockForUpdate()->findOrFail(
                OrderSeller::query()->whereKey($dispute->order_seller_id)->value('order_id'),
            );
            $orderSeller = OrderSeller::query()->lockForUpdate()->findOrFail($dispute->order_seller_id);
            $dispute = $this->lockedOpen($dispute);

            $refund = null;
            if ($refundBuyer) {
                $refund = $this->orderService->issueRefund(
                    $admin,
                    $orderSeller,
                    $amountCents,
                    "Disputa resolvida a favor do comprador: {$notes}",
                    "dispute:{$dispute->id}",
                    'order_seller.refunded_by_dispute',
                )['refund'];
            }

            $dispute->update([
                'status' => $refundBuyer ? DisputeStatus::RESOLVED_REFUNDED : DisputeStatus::RESOLVED_REJECTED,
                'resolution_notes' => $notes,
                'resolved_by' => $admin->id,
                'resolved_at' => now(),
                'refund_id' => $refund?->id,
            ]);

            event(new DisputeResolved($dispute));
            $this->audit($admin, $dispute, 'dispute.resolved', [
                'status' => $dispute->status->value,
                'refund_id' => $refund?->id,
                'refund_amount_cents' => $refund?->amount_cents,
            ]);

            return $dispute->refresh();
        });
    }

    private function lockedOpen(Dispute $dispute): Dispute
    {
        $dispute = Dispute::query()->lockForUpdate()->findOrFail($dispute->id);

        if (! $dispute->isOpen()) {
            throw new ConflictHttpException('A disputa já foi encerrada.');
        }

        return $dispute;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function audit(User $actor, Dispute $dispute, string $action, array $payload = []): void
    {
        AuditLog::query()->create([
            'action' => $action,
            'actor_id' => $actor->id,
            'actor_type' => $actor->getMorphClass(),
            'auditable_id' => $dispute->id,
            'auditable_type' => $dispute->getMorphClass(),
            'payload' => ['order_seller_id' => $dispute->order_seller_id, ...$payload],
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
        ]);
    }
}
