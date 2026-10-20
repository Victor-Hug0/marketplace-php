<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CartItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'offer_id' => $this->offer_id,
            'quantity' => $this->quantity,
            'unit_price' => Money::format($this->unitPriceCents()),
            'unit_price_cents' => $this->unitPriceCents(),
            'line_total' => Money::format($this->lineTotalCents()),
            'line_total_cents' => $this->lineTotalCents(),
            'offer' => $this->whenLoaded('offer', fn () => new OfferResource($this->offer)),
            'created_at' => $this->created_at?->timezone(config('app.timezone'))->toIso8601String(),
            'updated_at' => $this->updated_at?->timezone(config('app.timezone'))->toIso8601String(),
        ];
    }
}
