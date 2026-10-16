<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OrderSellerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'status' => $this->status,
            'subtotal' => (string) $this->subtotal,
            'shipping_total' => (string) $this->shipping_total,
            'total' => (string) $this->total,
            'store' => $this->whenLoaded('store', fn () => [
                'id' => $this->store->id,
                'store_name' => $this->store->store_name,
                'slug' => $this->store->slug,
                'status' => $this->store->status,
            ]),
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'status_histories' => OrderStatusHistoryResource::collection(
                $this->whenLoaded('statusHistories'),
            ),
            'created_at' => $this->created_at?->timezone(config('app.timezone'))->toIso8601String(),
            'updated_at' => $this->updated_at?->timezone(config('app.timezone'))->toIso8601String(),
        ];
    }
}
