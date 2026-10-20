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
use Knuckles\Scribe\Attributes as Doc;

#[Doc\Group('Produtos')]
final class ProductController extends Controller
{
    use CursorPaginates;

    public function __construct(
        private readonly ProductService $productService,
        private readonly BuyBoxService $buyBoxService,
    ) {}

    #[Doc\Subgroup('Público')]
    #[Doc\Endpoint('Buscar produtos', 'Só produtos `active` de lojas `active`, mais recentes primeiro. Filtros por texto (`q`), categoria (`category` ou `category_id`) e loja (`store` ou `store_id`).')]
    #[Doc\Unauthenticated]
    #[Doc\ResponseFromApiResource(ProductResource::class, Product::class, collection: true, cursorPaginate: 20, factoryStates: ['active'])]
    public function index(SearchProductsRequest $request): AnonymousResourceCollection
    {
        $query = $this->productService->publicCatalogQuery(
            $request->safe()->only([
                'q',
                'category_id',
                'category',
                'store_id',
                'store',
                'attr',
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

    #[Doc\Subgroup('Público')]
    #[Doc\Endpoint('Detalhar produto', 'Produto ativo com imagens, ofertas elegíveis e nota média. A oferta vencedora do Buy Box vem em `buy_box`, fora de `data`.')]
    #[Doc\Unauthenticated]
    #[Doc\ResponseFromApiResource(ProductResource::class, Product::class, factoryStates: ['active'], with: ['store', 'category', 'images'])]
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
                'attributeValues.categoryAttribute',
            ])
            ->withCount('reviews')
            ->withAvg('reviews', 'rating')
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
