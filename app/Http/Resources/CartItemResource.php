<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CartItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $unitPrice = $this->offer?->currentPrice() ?? '0.00';

        return [
            'id' => $this->id,
            'offer_id' => $this->offer_id,
            'quantity' => $this->quantity,
            'unit_price' => $unitPrice,
            'line_total' => $this->lineTotal(),
            'offer' => $this->whenLoaded('offer', fn () => new OfferResource($this->offer)),
            'created_at' => $this->created_at?->timezone(config('app.timezone'))->toIso8601String(),
            'updated_at' => $this->updated_at?->timezone(config('app.timezone'))->toIso8601String(),
        ];
    }
}
