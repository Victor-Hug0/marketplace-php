<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CartResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $items = $this->relationLoaded('items') ? $this->items : collect();

        $subtotal = $items->reduce(
            static fn (string $carry, $item): string => bcadd($carry, $item->lineTotal(), 2),
            '0.00',
        );

        $itemsCount = $items->sum('quantity');

        return [
            'id' => $this->id,
            'items_count' => (int) $itemsCount,
            'subtotal' => $subtotal,
            'currency' => 'BRL',
            'items' => CartItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->timezone(config('app.timezone'))->toIso8601String(),
            'updated_at' => $this->updated_at?->timezone(config('app.timezone'))->toIso8601String(),
        ];
    }
}
