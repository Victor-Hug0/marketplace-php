<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\PayoutProcessed;
use App\Notifications\PayoutProcessedNotification;
use App\Services\NotificationRecipients;

final class PayoutNotificationListener
{
    public function __construct(
        private readonly NotificationRecipients $recipients,
    ) {}

    public function handlePayoutProcessed(PayoutProcessed $event): void
    {
        $this->recipients->storeOwner($event->payout->loadMissing('store')->store)
            ?->notify(new PayoutProcessedNotification($event->payout));
    }
}
