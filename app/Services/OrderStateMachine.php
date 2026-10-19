<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderSellerStatus;
use App\Enums\OrderStatus;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class OrderStateMachine
{
    /**
     * @return array<string, list<string>>
     */
    public function orderTransitions(): array
    {
        return [
            OrderStatus::PENDING_PAYMENT->value => [
                OrderStatus::PAID->value,
                OrderStatus::CANCELLED->value,
            ],
            OrderStatus::PAID->value => [
                OrderStatus::PROCESSING->value,
                OrderStatus::CANCELLED->value,
                OrderStatus::REFUNDED->value,
            ],
            OrderStatus::PROCESSING->value => [
                OrderStatus::SHIPPED->value,
                OrderStatus::CANCELLED->value,
                OrderStatus::REFUNDED->value,
            ],
            OrderStatus::SHIPPED->value => [
                OrderStatus::DELIVERED->value,
            ],
            OrderStatus::DELIVERED->value => [],
            OrderStatus::CANCELLED->value => [],
            OrderStatus::REFUNDED->value => [],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public function orderSellerTransitions(): array
    {
        return [
            OrderSellerStatus::PENDING_PAYMENT->value => [
                OrderSellerStatus::PAID->value,
                OrderSellerStatus::CANCELLED->value,
            ],
            OrderSellerStatus::PAID->value => [
                OrderSellerStatus::PROCESSING->value,
                OrderSellerStatus::CANCELLED->value,
                OrderSellerStatus::REFUNDED->value,
            ],
            OrderSellerStatus::PROCESSING->value => [
                OrderSellerStatus::SHIPPED->value,
                OrderSellerStatus::CANCELLED->value,
                OrderSellerStatus::REFUNDED->value,
            ],
            OrderSellerStatus::SHIPPED->value => [
                OrderSellerStatus::DELIVERED->value,
            ],
            OrderSellerStatus::DELIVERED->value => [],
            OrderSellerStatus::CANCELLED->value => [],
            OrderSellerStatus::REFUNDED->value => [],
        ];
    }

    public function canTransitionOrder(OrderStatus $from, OrderStatus $to): bool
    {
        return in_array($to->value, $this->orderTransitions()[$from->value] ?? [], true);
    }

    public function canTransitionOrderSeller(OrderSellerStatus $from, OrderSellerStatus $to): bool
    {
        return in_array($to->value, $this->orderSellerTransitions()[$from->value] ?? [], true);
    }

    public function assertCanTransitionOrder(OrderStatus $from, OrderStatus $to): void
    {
        if (! $this->canTransitionOrder($from, $to)) {
            throw new ConflictHttpException(
                "Transição de pedido inválida: {$from->value} → {$to->value}.",
            );
        }
    }

    public function assertCanTransitionOrderSeller(OrderSellerStatus $from, OrderSellerStatus $to): void
    {
        if (! $this->canTransitionOrderSeller($from, $to)) {
            throw new ConflictHttpException(
                "Transição de subpedido inválida: {$from->value} → {$to->value}.",
            );
        }
    }

    /**
     * Seller may cancel only before shipping.
     */
    public function sellerCanCancel(OrderSellerStatus $status): bool
    {
        return in_array($status, [
            OrderSellerStatus::PENDING_PAYMENT,
            OrderSellerStatus::PAID,
            OrderSellerStatus::PROCESSING,
        ], true);
    }
}
