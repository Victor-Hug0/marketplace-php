<?php

declare(strict_types=1);

namespace App\Services;

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
            ->purchasable()
            ->with('store:id,store_name,slug,status')
            ->get();
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
