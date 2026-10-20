<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\PayoutStatus;
use App\Models\Payout;
use App\Support\Money;

final class PayoutProcessedNotification extends TransactionalNotification
{
    public function __construct(public readonly Payout $payout)
    {
        parent::__construct();
    }

    public function type(): string
    {
        return $this->paid() ? 'payout.paid' : 'payout.failed';
    }

    protected function title(): string
    {
        return $this->paid() ? 'Saque pago' : 'Saque não realizado';
    }

    protected function lines(): array
    {
        $amount = Money::formatBrl($this->payout->amount_cents);

        return $this->paid()
            ? ["O saque de {$amount} foi pago na conta cadastrada."]
            : [
                "O saque de {$amount} não pôde ser pago. Motivo: {$this->payout->failure_reason}",
                'O valor voltou ao saldo disponível da loja; você pode pedir um novo saque.',
            ];
    }

    protected function actionPath(): string
    {
        return '/vendedor/financeiro';
    }

    protected function data(): array
    {
        return [
            'payout_id' => $this->payout->id,
            'status' => $this->payout->status->value,
            'amount_cents' => $this->payout->amount_cents,
        ];
    }

    private function paid(): bool
    {
        return $this->payout->status === PayoutStatus::PAID;
    }
}
