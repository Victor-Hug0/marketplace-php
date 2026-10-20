<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\RoleName;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OfferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isOwnerOrAdmin = $user !== null && (
            $user->hasRole(RoleName::ADMIN)
            || ($this->relationLoaded('store')
                ? $this->store->user_id === $user->id
                : $this->store()->where('user_id', $user->id)->exists())
        );

        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'price' => Money::format($this->price_cents),
            'price_cents' => $this->price_cents,
            'promotional_price' => $this->promotional_price_cents !== null
                ? Money::format($this->promotional_price_cents)
                : null,
            'promotional_price_cents' => $this->promotional_price_cents,
            'current_price' => $this->currentPrice(),
            'current_price_cents' => $this->currentPriceCents(),
            'promo_starts_at' => $this->promo_starts_at?->timezone(config('app.timezone'))->toIso8601String(),
            'promo_ends_at' => $this->promo_ends_at?->timezone(config('app.timezone'))->toIso8601String(),
            'stock' => $this->when($isOwnerOrAdmin, $this->stock),
            'reserved_stock' => $this->when($isOwnerOrAdmin, $this->reserved_stock),
            'available_stock' => $this->availableStock(),
            'status' => $this->status,
            'store' => $this->whenLoaded('store', fn () => [
                'id' => $this->store->id,
                'store_name' => $this->store->store_name,
                'slug' => $this->store->slug,
            ]),
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'slug' => $this->product->slug,
            ]),
            'created_at' => $this->created_at?->timezone(config('app.timezone'))->toIso8601String(),
            'updated_at' => $this->updated_at?->timezone(config('app.timezone'))->toIso8601String(),
        ];
    }
}
