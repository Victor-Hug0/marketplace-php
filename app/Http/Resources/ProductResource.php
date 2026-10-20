<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ProductAttributeValue;
use App\Services\ProductReviewService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'weight_grams' => $this->weight_grams,
            'length_cm' => $this->length_cm,
            'width_cm' => $this->width_cm,
            'height_cm' => $this->height_cm,
            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'reviewed_at' => $this->reviewed_at?->timezone(config('app.timezone'))->toIso8601String(),
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ]),
            'store' => $this->whenLoaded('store', fn () => [
                'id' => $this->store->id,
                'store_name' => $this->store->store_name,
                'slug' => $this->store->slug,
            ]),
            'attributes' => $this->whenLoaded('attributeValues', fn (): array => $this->attributeValues
                ->sortBy(fn (ProductAttributeValue $value): int => $value->categoryAttribute->position)
                ->values()
                ->map(fn (ProductAttributeValue $value): array => [
                    'code' => $value->categoryAttribute->code,
                    'label' => $value->categoryAttribute->label,
                    'data_type' => $value->categoryAttribute->data_type,
                    'value' => $value->typedValue(),
                ])
                ->all()),
            'from_price' => $this->whenHas('from_price_cents', fn ($cents): ?string => $cents === null ? null : Money::format((int) $cents)),
            'from_price_cents' => $this->whenHas('from_price_cents', fn ($cents): ?int => $cents === null ? null : (int) $cents),
            'available_offers_count' => $this->whenHas('available_offers_count', fn ($count): int => (int) $count),
            'images' => ProductImageResource::collection($this->whenLoaded('images')),
            'offers' => OfferResource::collection($this->whenLoaded('offers')),
            'rating' => $this->whenCounted(
                'reviews',
                fn (int $count): array => ProductReviewService::summary($this->reviews_avg_rating, $count),
            ),
            'created_at' => $this->created_at?->timezone(config('app.timezone'))->toIso8601String(),
            'updated_at' => $this->updated_at?->timezone(config('app.timezone'))->toIso8601String(),
        ];
    }
}
