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
            'subtotal_cents' => $this->subtotal_cents,
            'shipping_cents' => $this->shipping_cents,
            'total_cents' => $this->total_cents,
            'shipping' => [
                'service_code' => $this->shipping_service_code,
                'service_name' => $this->shipping_service_name,
                'eta_days' => $this->shipping_eta_days,
                'quoted_at' => $this->shipping_quoted_at?->timezone(config('app.timezone'))->toIso8601String(),
            ],
            'shipment' => new ShipmentResource($this->whenLoaded('shipment')),
            'dispute' => new DisputeResource($this->whenLoaded('dispute')),
            'return_request' => new ReturnRequestResource($this->whenLoaded('returnRequest')),
            'store' => $this->whenLoaded('store', fn () => [
                'id' => $this->store->id,
                'store_name' => $this->store->store_name,
                'slug' => $this->store->slug,
                'status' => $this->store->status,
            ]),
            'order_id' => $this->order_id,
            'order' => $this->whenLoaded('order', fn () => [
                'id' => $this->order->id,
                'buyer_id' => $this->order->buyer_id,
                'status' => $this->order->status,
                'currency' => $this->order->currency,
                'subtotal_cents' => $this->order->subtotal_cents,
                'shipping_cents' => $this->order->shipping_cents,
                'total_cents' => $this->order->total_cents,
                'created_at' => $this->order->created_at
                    ?->timezone(config('app.timezone'))
                    ->toIso8601String(),
            ]),
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'refunded_cents' => $this->whenLoaded('refunds', fn (): int => (int) $this->refunds->sum('amount_cents')),
            'refunds' => RefundResource::collection($this->whenLoaded('refunds')),
            'status_histories' => OrderStatusHistoryResource::collection(
                $this->whenLoaded('statusHistories'),
            ),
            'created_at' => $this->created_at?->timezone(config('app.timezone'))->toIso8601String(),
            'updated_at' => $this->updated_at?->timezone(config('app.timezone'))->toIso8601String(),
        ];
    }
}
