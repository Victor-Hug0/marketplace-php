<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PayoutStatus;
use App\Enums\StoreStatus;
use App\Models\AuditLog;
use App\Models\Payout;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class PayoutService
{
    public function __construct(
        private readonly LedgerService $ledgerService,
    ) {}

    /**
     * @return Builder<Payout>
     */
    public function storePayoutsQuery(Store $store): Builder
    {
        return Payout::query()->where('store_id', $store->id);
    }

    /**
     * @return Builder<Payout>
     */
    public function adminPayoutsQuery(?PayoutStatus $status = null): Builder
    {
        return Payout::query()
            ->with('store:id,store_name,slug,status,user_id')
            ->when($status !== null, static fn (Builder $query): Builder => $query->where('status', $status));
    }

    /**
     * @return array{payout: Payout, created: bool}
     */
    public function request(User $seller, int $amountCents, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($seller, $amountCents, $idempotencyKey): array {
            $store = Store::query()
                ->whereBelongsTo($seller, 'owner')
                ->lockForUpdate()
                ->firstOrFail();

            $replay = Payout::query()
                ->where('store_id', $store->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($replay !== null) {
                if ($replay->amount_cents !== $amountCents) {
                    throw new ConflictHttpException(
                        'A chave de idempotência já foi usada com outro valor de saque.',
                    );
                }

                return ['payout' => $replay, 'created' => false];
            }

            if ($store->status !== StoreStatus::ACTIVE) {
                throw new ConflictHttpException('Somente lojas ativas podem solicitar saque.');
            }

            if ($amountCents > $this->ledgerService->availableBalanceCents($store)) {
                throw new ConflictHttpException('Saldo disponível insuficiente para o saque.');
            }

            $payout = Payout::query()->create([
                'store_id' => $store->id,
                'requested_by' => $seller->id,
                'status' => PayoutStatus::REQUESTED,
                'amount_cents' => $amountCents,
                'currency' => 'BRL',
                'idempotency_key' => $idempotencyKey,
            ]);

            $this->audit($seller, $payout, 'payout.requested', [
                'store_id' => $store->id,
                'amount_cents' => $amountCents,
            ]);

            return ['payout' => $payout, 'created' => true];
        });
    }

    public function markPaid(User $admin, Payout $payout): Payout
    {
        return DB::transaction(function () use ($admin, $payout): Payout {
            $payout = $this->lockRequested($payout);

            $payout->update([
                'status' => PayoutStatus::PAID,
                'paid_at' => now(),
                'processed_by' => $admin->id,
            ]);

            $this->ledgerService->postPayout($payout);

            $this->audit($admin, $payout, 'payout.marked_paid', [
                'store_id' => $payout->store_id,
                'amount_cents' => $payout->amount_cents,
            ]);

            return $payout->refresh();
        });
    }

    public function markFailed(User $admin, Payout $payout, string $reason): Payout
    {
        return DB::transaction(function () use ($admin, $payout, $reason): Payout {
            $payout = $this->lockRequested($payout);

            $payout->update([
                'status' => PayoutStatus::FAILED,
                'failed_at' => now(),
                'failure_reason' => $reason,
                'processed_by' => $admin->id,
            ]);

            $this->audit($admin, $payout, 'payout.marked_failed', [
                'store_id' => $payout->store_id,
                'amount_cents' => $payout->amount_cents,
                'reason' => $reason,
            ]);

            return $payout->refresh();
        });
    }

    private function lockRequested(Payout $payout): Payout
    {
        Store::query()->lockForUpdate()->findOrFail($payout->store_id);
        $payout = Payout::query()->lockForUpdate()->findOrFail($payout->id);

        if ($payout->status !== PayoutStatus::REQUESTED) {
            throw new ConflictHttpException('Somente saques em requested podem ser processados.');
        }

        return $payout;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function audit(User $actor, Payout $payout, string $action, array $payload): void
    {
        AuditLog::query()->create([
            'action' => $action,
            'actor_id' => $actor->id,
            'actor_type' => $actor->getMorphClass(),
            'auditable_id' => $payout->id,
            'auditable_type' => $payout->getMorphClass(),
            'payload' => $payload,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
        ]);
    }
}
