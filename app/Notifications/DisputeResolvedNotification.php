<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\DisputeStatus;
use App\Models\Dispute;
use App\Support\Money;

final class DisputeResolvedNotification extends TransactionalNotification
{
    public const FOR_BUYER = 'buyer';

    public const FOR_SELLER = 'seller';

    public function __construct(
        public readonly Dispute $dispute,
        public readonly string $audience,
    ) {
        parent::__construct();
    }

    public function type(): string
    {
        return 'dispute.resolved';
    }

    protected function prepare(): void
    {
        $this->dispute->loadMissing('refund');
    }

    protected function title(): string
    {
        return 'Disputa encerrada';
    }

    protected function lines(): array
    {
        $refunded = $this->dispute->status === DisputeStatus::RESOLVED_REFUNDED;
        $amount = Money::formatBrl((int) ($this->dispute->refund?->amount_cents ?? 0));

        $outcome = match (true) {
            $refunded && $this->audience === self::FOR_BUYER => "A disputa foi decidida a seu favor: reembolso de {$amount}.",
            $refunded => "A disputa foi decidida a favor do comprador, com reembolso de {$amount} debitado do saldo da loja.",
            $this->audience === self::FOR_BUYER => 'A disputa foi encerrada sem reembolso.',
            default => 'A disputa foi encerrada sem reembolso; o valor da venda voltou a ficar disponível.',
        };

        return [$outcome, 'Observação do marketplace: '.$this->dispute->resolution_notes];
    }

    protected function actionPath(): string
    {
        return $this->audience === self::FOR_BUYER
            ? "/pedidos/disputas/{$this->dispute->id}"
            : "/vendedor/disputas/{$this->dispute->id}";
    }

    protected function data(): array
    {
        return [
            'dispute_id' => $this->dispute->id,
            'status' => $this->dispute->status->value,
            'refund_cents' => $this->dispute->refund?->amount_cents,
        ];
    }
}
