<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DisputeResource extends JsonResource
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
            'description' => $this->description,
            'seller_response' => $this->seller_response,
            'seller_responded_at' => $this->seller_responded_at?->timezone(config('app.timezone'))->toIso8601String(),
            'resolution_notes' => $this->resolution_notes,
            'resolved_at' => $this->resolved_at?->timezone(config('app.timezone'))->toIso8601String(),
            'refund_id' => $this->refund_id,
            'created_at' => $this->created_at?->timezone(config('app.timezone'))->toIso8601String(),
        ];
    }
}
