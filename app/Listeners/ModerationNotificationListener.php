<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ProductDecided;
use App\Events\ProductSubmitted;
use App\Events\StoreDecided;
use App\Events\StoreRequested;
use App\Notifications\ProductDecidedNotification;
use App\Notifications\ProductSubmittedNotification;
use App\Notifications\StoreDecidedNotification;
use App\Notifications\StoreRequestedNotification;
use App\Services\NotificationRecipients;
use Illuminate\Support\Facades\Notification;

final class ModerationNotificationListener
{
    public function __construct(
        private readonly NotificationRecipients $recipients,
    ) {}

    public function handleStoreRequested(StoreRequested $event): void
    {
        Notification::send($this->recipients->admins(), new StoreRequestedNotification($event->store));
    }

    public function handleStoreDecided(StoreDecided $event): void
    {
        $this->recipients->storeOwner($event->store)?->notify(new StoreDecidedNotification($event->store));
    }

    public function handleProductSubmitted(ProductSubmitted $event): void
    {
        Notification::send($this->recipients->admins(), new ProductSubmittedNotification($event->product));
    }

    public function handleProductDecided(ProductDecided $event): void
    {
        $this->recipients->storeOwner($event->product->loadMissing('store')->store)
            ?->notify(new ProductDecidedNotification($event->product));
    }
}
