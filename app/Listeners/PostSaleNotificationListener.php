<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\DisputeOpened;
use App\Events\DisputeResolved;
use App\Events\ReturnRequestDecided;
use App\Events\ReturnRequested;
use App\Models\OrderSeller;
use App\Models\User;
use App\Notifications\DisputeOpenedNotification;
use App\Notifications\DisputeResolvedNotification;
use App\Notifications\ReturnRequestDecidedNotification;
use App\Notifications\ReturnRequestedNotification;
use App\Services\NotificationRecipients;
use Illuminate\Support\Facades\Notification;

final class PostSaleNotificationListener
{
    public function __construct(
        private readonly NotificationRecipients $recipients,
    ) {}

    public function handleDisputeOpened(DisputeOpened $event): void
    {
        $dispute = $event->dispute;

        $this->seller($dispute->loadMissing('orderSeller')->orderSeller)
            ?->notify(new DisputeOpenedNotification($dispute, DisputeOpenedNotification::FOR_SELLER));

        Notification::send(
            $this->recipients->admins(),
            new DisputeOpenedNotification($dispute, DisputeOpenedNotification::FOR_ADMIN),
        );
    }

    public function handleDisputeResolved(DisputeResolved $event): void
    {
        $dispute = $event->dispute;
        $orderSeller = $dispute->loadMissing('orderSeller')->orderSeller;

        $this->buyer($orderSeller)?->notify(new DisputeResolvedNotification($dispute, DisputeResolvedNotification::FOR_BUYER));
        $this->seller($orderSeller)?->notify(new DisputeResolvedNotification($dispute, DisputeResolvedNotification::FOR_SELLER));
    }

    public function handleReturnRequested(ReturnRequested $event): void
    {
        $this->seller($event->returnRequest->loadMissing('orderSeller')->orderSeller)
            ?->notify(new ReturnRequestedNotification($event->returnRequest));
    }

    public function handleReturnRequestDecided(ReturnRequestDecided $event): void
    {
        $this->buyer($event->returnRequest->loadMissing('orderSeller')->orderSeller)
            ?->notify(new ReturnRequestDecidedNotification($event->returnRequest));
    }

    private function buyer(OrderSeller $orderSeller): ?User
    {
        return $this->recipients->buyer($orderSeller->loadMissing('order')->order);
    }

    private function seller(OrderSeller $orderSeller): ?User
    {
        return $this->recipients->storeOwner($orderSeller->loadMissing('store')->store);
    }
}
