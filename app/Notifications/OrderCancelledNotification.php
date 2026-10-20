<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Order;

final class OrderCancelledNotification extends TransactionalNotification
{
    public const CAUSE_BUYER = 'buyer';

    public const CAUSE_EXPIRED = 'expired';

    public function __construct(
        public readonly Order $order,
        public readonly string $cause,
    ) {
        parent::__construct();
    }

    public function type(): string
    {
        return 'order.cancelled';
    }

    protected function title(): string
    {
        return $this->cause === self::CAUSE_EXPIRED
            ? 'Pedido cancelado por falta de pagamento'
            : 'Pedido cancelado';
    }

    protected function lines(): array
    {
        return [
            $this->cause === self::CAUSE_EXPIRED
                ? 'O prazo de pagamento terminou sem confirmação, então o pedido foi cancelado e os produtos voltaram ao estoque.'
                : 'Seu pedido foi cancelado como você pediu. Nenhum valor foi cobrado.',
            'Se ainda quiser os produtos, é só fazer um novo pedido.',
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
            'cause' => $this->cause,
        ];
    }
}
