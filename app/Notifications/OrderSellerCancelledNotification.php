<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\OrderSeller;
use App\Support\Money;

final class OrderSellerCancelledNotification extends TransactionalNotification
{
    public function __construct(public readonly OrderSeller $orderSeller)
    {
        parent::__construct();
    }

    public function type(): string
    {
        return 'order_seller.cancelled_by_seller';
    }

    protected function prepare(): void
    {
        $this->orderSeller->loadMissing(['store', 'refunds']);
    }

    protected function title(): string
    {
        return 'Uma loja cancelou parte do seu pedido';
    }

    protected function lines(): array
    {
        $refunded = (int) $this->orderSeller->refunds->sum('amount_cents');

        return array_values(array_filter([
            'A loja '.$this->orderSeller->store->store_name.' cancelou os itens dela no seu pedido.',
            $refunded > 0 ? 'Reembolso de '.Money::formatBrl($refunded).' a caminho, pelo mesmo meio de pagamento.' : null,
            'Os itens das outras lojas continuam normalmente.',
        ]));
    }

    protected function actionPath(): string
    {
        return "/pedidos/{$this->orderSeller->order_id}";
    }

    protected function data(): array
    {
        return [
            'order_seller_id' => $this->orderSeller->id,
            'order_id' => $this->orderSeller->order_id,
            'refunded_cents' => (int) $this->orderSeller->refunds->sum('amount_cents'),
        ];
    }
}
