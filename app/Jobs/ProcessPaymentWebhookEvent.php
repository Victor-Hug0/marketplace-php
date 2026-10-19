<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\PaymentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessPaymentWebhookEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @var list<int>
     */
    public array $backoff = [5, 30, 120, 300];

    public function __construct(
        public readonly string $webhookEventId,
    ) {}

    public function handle(PaymentService $paymentService): void
    {
        $paymentService->processWebhookEvent($this->webhookEventId);
    }
}
