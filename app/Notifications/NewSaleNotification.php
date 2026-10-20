<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\OrderSeller;
use App\Support\Money;

final class NewSaleNotification extends TransactionalNotification
{
    public function __construct(public readonly OrderSeller $orderSeller)
    {
        parent::__construct();
    }

    public function type(): string
    {
        return 'sale.paid';
    }

    protected function prepare(): void
    {
        $this->orderSeller->loadMissing('items');
    }

    protected function title(): string
    {
        return 'Nova venda paga';
    }

    protected function lines(): array
    {
        return [
            'Você vendeu '.$this->orderSeller->items->sum('quantity').' item(ns), total de '.Money::formatBrl($this->orderSeller->total_cents).' (com frete).',
            'O pagamento já foi confirmado: separe e envie o pedido pela modalidade '.($this->orderSeller->shipping_service_name ?? 'contratada').'.',
        ];
    }

    protected function actionPath(): string
    {
        return "/vendedor/subpedidos/{$this->orderSeller->id}";
    }

    protected function actionText(): string
    {
        return 'Ver venda';
    }

    protected function data(): array
    {
        return [
            'order_seller_id' => $this->orderSeller->id,
            'order_id' => $this->orderSeller->order_id,
            'total_cents' => $this->orderSeller->total_cents,
        ];
    }
}
