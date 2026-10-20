<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ReturnRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_seller_id' => $this->order_seller_id,
            'status' => $this->status,
            'reason' => $this->reason,
            'amount_cents' => $this->amount_cents,
            'decision_notes' => $this->decision_notes,
            'decided_at' => $this->decided_at?->timezone(config('app.timezone'))->toIso8601String(),
            'refund_id' => $this->refund_id,
            'created_at' => $this->created_at?->timezone(config('app.timezone'))->toIso8601String(),
        ];
    }
}
