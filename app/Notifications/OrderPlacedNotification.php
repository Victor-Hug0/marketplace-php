<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Order;
use App\Support\Money;

final class OrderPlacedNotification extends TransactionalNotification
{
    public function __construct(public readonly Order $order)
    {
        parent::__construct();
    }

    public function type(): string
    {
        return 'order.placed';
    }

    protected function prepare(): void
    {
        $this->order->loadMissing('sellers');
    }

    protected function title(): string
    {
        return 'Recebemos seu pedido';
    }

    protected function lines(): array
    {
        return [
            'Seu pedido foi criado com '.$this->order->sellers->count().' entrega(s), uma por loja.',
            'Produtos: '.Money::formatBrl($this->order->subtotal_cents).' | Frete: '.Money::formatBrl($this->order->shipping_cents).' | Total: '.Money::formatBrl($this->order->total_cents).'.',
            'Pague até '.$this->dateTime($this->order->payment_due_at).'; depois disso o pedido é cancelado e o estoque liberado.',
        ];
    }

    protected function actionPath(): string
    {
        return "/pedidos/{$this->order->id}";
    }

    protected function actionText(): string
    {
        return 'Pagar pedido';
    }

    protected function data(): array
    {
        return [
            'order_id' => $this->order->id,
            'total_cents' => $this->order->total_cents,
            'payment_due_at' => $this->order->payment_due_at?->toIso8601String(),
        ];
    }
}
