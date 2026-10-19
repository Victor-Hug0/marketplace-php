<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PaymentWebhookEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'event_id' => $this->event_id,
            'type' => $this->type,
            'processed' => $this->isProcessed(),
            'result' => $this->result,
            'processed_at' => $this->processed_at?->timezone(config('app.timezone'))->toIso8601String(),
            'received_at' => $this->created_at?->timezone(config('app.timezone'))->toIso8601String(),
        ];
    }
}
