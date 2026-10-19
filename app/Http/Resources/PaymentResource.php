<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'provider' => $this->provider,
            'method' => $this->method,
            'status' => $this->status,
            'external_id' => $this->external_id,
            'currency' => $this->currency,
            'amount_cents' => $this->amount_cents,
            'instructions' => $this->payload ?? [],
            'paid_at' => $this->paid_at?->timezone(config('app.timezone'))->toIso8601String(),
            'failed_at' => $this->failed_at?->timezone(config('app.timezone'))->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->timezone(config('app.timezone'))->toIso8601String(),
            'created_at' => $this->created_at?->timezone(config('app.timezone'))->toIso8601String(),
            'updated_at' => $this->updated_at?->timezone(config('app.timezone'))->toIso8601String(),
        ];
    }
}
