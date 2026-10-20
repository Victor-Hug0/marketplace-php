<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\OrderSeller;

final class OrderSellerDeliveredNotification extends TransactionalNotification
{
    public function __construct(public readonly OrderSeller $orderSeller)
    {
        parent::__construct();
    }

    public function type(): string
    {
        return 'order_seller.delivered';
    }

    protected function prepare(): void
    {
        $this->orderSeller->loadMissing('store');
    }

    protected function title(): string
    {
        return 'Pedido entregue';
    }

    protected function lines(): array
    {
        $days = (int) config('post_sale.dispute_window_days');

        return [
            'Os itens da loja '.$this->orderSeller->store->store_name.' foram entregues.',
            'Conte como foi avaliando os produtos. Se algo deu errado, você tem '.$days.' dias para abrir uma disputa ou pedir devolução.',
        ];
    }

    protected function actionPath(): string
    {
        return "/pedidos/{$this->orderSeller->order_id}";
    }

    protected function actionText(): string
    {
        return 'Avaliar compra';
    }

    protected function data(): array
    {
        return [
            'order_seller_id' => $this->orderSeller->id,
            'order_id' => $this->orderSeller->order_id,
        ];
    }
}
