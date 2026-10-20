<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\ProductReviewService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

final class PublicStoreResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_name' => $this->store_name,
            'slug' => $this->slug,
            'description' => $this->description,
            'logo_url' => $this->logo_path
                ? Storage::url($this->logo_path)
                : null,
            'banner_url' => $this->banner_path
                ? Storage::url($this->banner_path)
                : null,
            'rating' => $this->whenCounted(
                'productReviews',
                fn (int $count): array => ProductReviewService::summary($this->product_reviews_avg_rating, $count),
            ),
        ];
    }
}
