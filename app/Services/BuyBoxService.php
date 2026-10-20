<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OfferStatus;
use App\Enums\StoreStatus;
use App\Models\Offer;
use App\Models\Product;
use Illuminate\Support\Collection;

final class BuyBoxService
{
    /**
     * @return Collection<int, Offer>
     */
    public function eligibleOffers(Product $product): Collection
    {
        return Offer::query()
            ->where('product_id', $product->id)
            ->where('status', OfferStatus::ACTIVE)
            ->whereHas('store', fn ($query) => $query->where('status', StoreStatus::ACTIVE))
            ->with('store:id,store_name,slug,status')
            ->get()
            ->filter(fn (Offer $offer): bool => $offer->availableStock() > 0)
            ->values();
    }

    public function winner(Product $product): ?Offer
    {
        $eligible = $this->eligibleOffers($product);

        if ($eligible->isEmpty()) {
            return null;
        }

        return $eligible
            ->sort(function (Offer $a, Offer $b): int {
                $priceCmp = $a->currentPriceCents() <=> $b->currentPriceCents();
                if ($priceCmp !== 0) {
                    return $priceCmp;
                }

                $stockCmp = $b->availableStock() <=> $a->availableStock();
                if ($stockCmp !== 0) {
                    return $stockCmp;
                }

                return $a->created_at <=> $b->created_at;
            })
            ->first();
    }
}
