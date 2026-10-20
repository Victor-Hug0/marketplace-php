<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Money;
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

        $subtotalCents = (int) $items->sum(static fn ($item): int => $item->lineTotalCents());

        $itemsCount = $items->sum('quantity');

        return [
            'id' => $this->id,
            'items_count' => (int) $itemsCount,
            'subtotal' => Money::format($subtotalCents),
            'subtotal_cents' => $subtotalCents,
            'currency' => 'BRL',
            'items' => CartItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->timezone(config('app.timezone'))->toIso8601String(),
            'updated_at' => $this->updated_at?->timezone(config('app.timezone'))->toIso8601String(),
        ];
    }
}
