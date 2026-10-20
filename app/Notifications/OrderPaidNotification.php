<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Order;
use App\Support\Money;

final class OrderPaidNotification extends TransactionalNotification
{
    public function __construct(public readonly Order $order)
    {
        parent::__construct();
    }

    public function type(): string
    {
        return 'order.paid';
    }

    protected function title(): string
    {
        return 'Pagamento aprovado';
    }

    protected function lines(): array
    {
        return [
            'Recebemos o pagamento de '.Money::formatBrl($this->order->total_cents).'.',
            'As lojas já foram avisadas e você recebe um e-mail a cada envio, com o código de rastreio.',
        ];
    }

    protected function actionPath(): string
    {
        return "/pedidos/{$this->order->id}";
    }

    protected function data(): array
    {
        return [
            'order_id' => $this->order->id,
            'total_cents' => $this->order->total_cents,
        ];
    }
}
