<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'currency' => $this->currency,
            'subtotal_cents' => $this->subtotal_cents,
            'shipping_cents' => $this->shipping_cents,
            'total_cents' => $this->total_cents,
            'address_id' => $this->address_id,
            'address_snapshot' => $this->shippingAddressSnapshot(),
            'idempotency_key' => $this->idempotency_key,
            'payment_due_at' => $this->payment_due_at?->timezone(config('app.timezone'))->toIso8601String(),
            'sellers' => OrderSellerResource::collection($this->whenLoaded('sellers')),
            'status_histories' => OrderStatusHistoryResource::collection(
                $this->whenLoaded('statusHistories'),
            ),
            'created_at' => $this->created_at?->timezone(config('app.timezone'))->toIso8601String(),
            'updated_at' => $this->updated_at?->timezone(config('app.timezone'))->toIso8601String(),
        ];
    }
}
