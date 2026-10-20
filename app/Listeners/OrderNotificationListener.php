<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\OrderSellerStatus;
use App\Events\OrderCancelled;
use App\Events\OrderPaid;
use App\Events\OrderPlaced;
use App\Events\OrderSellerCancelledBySeller;
use App\Events\OrderSellerDelivered;
use App\Events\OrderSellerShipped;
use App\Events\PaymentIntentCreated;
use App\Models\OrderSeller;
use App\Notifications\NewSaleNotification;
use App\Notifications\OrderCancelledNotification;
use App\Notifications\OrderPaidNotification;
use App\Notifications\OrderPlacedNotification;
use App\Notifications\OrderSellerCancelledNotification;
use App\Notifications\OrderSellerDeliveredNotification;
use App\Notifications\OrderSellerShippedNotification;
use App\Notifications\PaymentInstructionsNotification;
use App\Services\NotificationRecipients;

final class OrderNotificationListener
{
    public function __construct(
        private readonly NotificationRecipients $recipients,
    ) {}

    public function handleOrderPlaced(OrderPlaced $event): void
    {
        $this->recipients->buyer($event->order)?->notify(new OrderPlacedNotification($event->order));
    }

    public function handlePaymentIntentCreated(PaymentIntentCreated $event): void
    {
        $order = $event->payment->loadMissing('order')->order;

        $this->recipients->buyer($order)?->notify(new PaymentInstructionsNotification($event->payment));
    }

    public function handleOrderPaid(OrderPaid $event): void
    {
        $order = $event->order;

        $this->recipients->buyer($order)?->notify(new OrderPaidNotification($order));

        $order->sellers()
            ->where('status', OrderSellerStatus::PAID)
            ->with('store.owner')
            ->get()
            ->each(fn (OrderSeller $orderSeller) => $orderSeller->store->owner?->notify(new NewSaleNotification($orderSeller)));
    }

    public function handleOrderSellerShipped(OrderSellerShipped $event): void
    {
        $this->notifyBuyerOf($event->orderSeller, new OrderSellerShippedNotification($event->orderSeller));
    }

    public function handleOrderSellerDelivered(OrderSellerDelivered $event): void
    {
        $this->notifyBuyerOf($event->orderSeller, new OrderSellerDeliveredNotification($event->orderSeller));
    }

    public function handleOrderCancelled(OrderCancelled $event): void
    {
        $this->recipients->buyer($event->order)?->notify(new OrderCancelledNotification($event->order, $event->cause));
    }

    public function handleOrderSellerCancelledBySeller(OrderSellerCancelledBySeller $event): void
    {
        $this->notifyBuyerOf($event->orderSeller, new OrderSellerCancelledNotification($event->orderSeller));
    }

    private function notifyBuyerOf(OrderSeller $orderSeller, object $notification): void
    {
        $this->recipients->buyer($orderSeller->loadMissing('order')->order)?->notify($notification);
    }
}
