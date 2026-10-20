<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\OrderSeller;

final class OrderSellerShippedNotification extends TransactionalNotification
{
    public function __construct(public readonly OrderSeller $orderSeller)
    {
        parent::__construct();
    }

    public function type(): string
    {
        return 'order_seller.shipped';
    }

    protected function prepare(): void
    {
        $this->orderSeller->loadMissing(['store', 'shipment']);
    }

    protected function title(): string
    {
        return 'Seu pedido foi enviado';
    }

    protected function lines(): array
    {
        $shipment = $this->orderSeller->shipment;

        return [
            'A loja '.$this->orderSeller->store->store_name.' enviou seus itens.',
            'Código de rastreio: '.($shipment?->tracking_code ?? '-').'.',
            'Previsão de entrega: '.($shipment?->estimated_delivery_at?->timezone(config('app.timezone'))->format('d/m/Y') ?? '-').'.',
        ];
    }

    protected function actionPath(): string
    {
        return "/pedidos/{$this->orderSeller->order_id}";
    }

    protected function actionText(): string
    {
        return 'Acompanhar pedido';
    }

    protected function data(): array
    {
        return [
            'order_seller_id' => $this->orderSeller->id,
            'order_id' => $this->orderSeller->order_id,
            'tracking_code' => $this->orderSeller->shipment?->tracking_code,
        ];
    }
}
