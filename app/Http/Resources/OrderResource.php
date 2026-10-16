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
            'subtotal' => (string) $this->subtotal,
            'shipping_total' => (string) $this->shipping_total,
            'total' => (string) $this->total,
            'address_snapshot' => $this->address_snapshot,
            'idempotency_key' => $this->idempotency_key,
            'sellers' => OrderSellerResource::collection($this->whenLoaded('sellers')),
            'status_histories' => OrderStatusHistoryResource::collection(
                $this->whenLoaded('statusHistories'),
            ),
            'created_at' => $this->created_at?->timezone(config('app.timezone'))->toIso8601String(),
            'updated_at' => $this->updated_at?->timezone(config('app.timezone'))->toIso8601String(),
        ];
    }
}
