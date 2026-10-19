<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class RefundResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_seller_id' => $this->order_seller_id,
            'payment_id' => $this->payment_id,
            'external_id' => $this->external_id,
            'amount_cents' => $this->amount_cents,
            'currency' => $this->currency,
            'reason' => $this->reason,
            'restocked' => $this->restocked,
            'processed_at' => $this->processed_at?->timezone(config('app.timezone'))->toIso8601String(),
            'created_at' => $this->created_at?->timezone(config('app.timezone'))->toIso8601String(),
        ];
    }
}
