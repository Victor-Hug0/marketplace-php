<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\ReturnRequestStatus;
use App\Models\ReturnRequest;
use App\Support\Money;

final class ReturnRequestDecidedNotification extends TransactionalNotification
{
    public function __construct(public readonly ReturnRequest $returnRequest)
    {
        parent::__construct();
    }

    public function type(): string
    {
        return 'return_request.decided';
    }

    protected function title(): string
    {
        return $this->approved() ? 'Devolução aprovada' : 'Devolução recusada';
    }

    protected function lines(): array
    {
        return array_values(array_filter([
            $this->approved()
                ? 'Sua devolução foi aprovada: reembolso de '.Money::formatBrl($this->returnRequest->amount_cents).' a caminho.'
                : 'Sua devolução foi recusada.',
            $this->returnRequest->decision_notes !== null ? 'Observação: '.$this->returnRequest->decision_notes : null,
        ]));
    }

    protected function actionPath(): string
    {
        return "/pedidos/devolucoes/{$this->returnRequest->id}";
    }

    protected function data(): array
    {
        return [
            'return_request_id' => $this->returnRequest->id,
            'status' => $this->returnRequest->status->value,
            'amount_cents' => $this->returnRequest->amount_cents,
        ];
    }

    private function approved(): bool
    {
        return $this->returnRequest->status === ReturnRequestStatus::APPROVED;
    }
}
