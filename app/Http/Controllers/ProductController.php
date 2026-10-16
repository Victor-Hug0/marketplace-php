<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ProductStatus;
use App\Enums\StoreStatus;
use App\Http\Requests\SearchProductsRequest;
use App\Http\Resources\OfferResource;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\BuyBoxService;
use App\Services\ProductService;
use App\Support\Pagination\CursorPaginates;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ProductController extends Controller
{
    use CursorPaginates;

    public function __construct(
        private readonly ProductService $productService,
        private readonly BuyBoxService $buyBoxService,
    ) {}

    public function index(SearchProductsRequest $request): AnonymousResourceCollection
    {
        $query = $this->productService->publicCatalogQuery(
            $request->safe()->only([
                'q',
                'category_id',
                'category',
                'store_id',
                'store',
            ]),
        );

        return ProductResource::collection(
            $this->cursorPaginate(
                $query,
                $request,
                cursorColumn: 'id',
                descending: true,
            ),
        );
    }

    public function show(string $slug): ProductResource
    {
        $product = Product::query()
            ->where('slug', $slug)
            ->where('status', ProductStatus::ACTIVE)
            ->whereHas(
                'store',
                fn ($query) => $query->where('status', StoreStatus::ACTIVE),
            )
            ->with([
                'images',
                'store:id,store_name,slug,status',
                'category:id,name,slug',
            ])
            ->firstOrFail();

        $eligibleOffers = $this->buyBoxService->eligibleOffers($product);
        $buyBox = $this->buyBoxService->winner($product);

        $product->setRelation('offers', $eligibleOffers);

        return (new ProductResource($product))->additional([
            'buy_box' => $buyBox !== null
                ? (new OfferResource($buyBox))->resolve()
                : null,
        ]);
    }
}
