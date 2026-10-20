<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\ReturnRequest;
use App\Support\Money;

final class ReturnRequestedNotification extends TransactionalNotification
{
    public function __construct(public readonly ReturnRequest $returnRequest)
    {
        parent::__construct();
    }

    public function type(): string
    {
        return 'return_request.created';
    }

    protected function title(): string
    {
        return 'Pedido de devolução recebido';
    }

    protected function lines(): array
    {
        return [
            'Um comprador pediu devolução de '.Money::formatBrl($this->returnRequest->amount_cents).'.',
            'Aprove ou recuse pelo painel do vendedor; o valor fica retido até a decisão.',
        ];
    }

    protected function actionPath(): string
    {
        return "/vendedor/devolucoes/{$this->returnRequest->id}";
    }

    protected function actionText(): string
    {
        return 'Analisar devolução';
    }

    protected function data(): array
    {
        return [
            'return_request_id' => $this->returnRequest->id,
            'order_seller_id' => $this->returnRequest->order_seller_id,
            'amount_cents' => $this->returnRequest->amount_cents,
        ];
    }
}
