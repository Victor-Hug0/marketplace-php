<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\StoreStatus;
use App\Http\Requests\CreateStoreRequest;
use App\Http\Requests\SearchProductsRequest;
use App\Http\Resources\ProductResource;
use App\Http\Resources\PublicStoreResource;
use App\Http\Resources\StoreResource;
use App\Models\Store;
use App\Services\ProductService;
use App\Services\StoreService;
use App\Support\Pagination\CursorPaginates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes as Doc;

#[Doc\Group('Lojas')]
final class StoreController extends Controller
{
    use CursorPaginates;

    public function __construct(
        private readonly StoreService $storeService,
        private readonly ProductService $productService,
    ) {}

    #[Doc\Subgroup('Comprador')]
    #[Doc\Endpoint('Solicitar loja', 'Cria a loja em `pending`. Uma loja por usuário, inclusive contando rejeitadas. O papel `seller` só é concedido quando o admin aprova.')]
    #[Doc\ResponseFromApiResource(StoreResource::class, Store::class, status: 201)]
    public function store(CreateStoreRequest $request): JsonResponse
    {
        $store = $this->storeService->requestStore(
            $request->user(),
            $request->validated(),
        );

        return (new StoreResource($store))
            ->additional(['message' => 'Solicitação de loja criada com sucesso.'])
            ->response()
            ->setStatusCode(201);
    }

    #[Doc\Subgroup('Comprador')]
    #[Doc\Endpoint('Minha loja', 'Loja do usuário autenticado, com documento e status.')]
    #[Doc\ResponseFromApiResource(StoreResource::class, Store::class)]
    public function me(Request $request): StoreResource
    {
        $store = Store::query()
            ->whereBelongsTo($request->user(), 'owner')
            ->firstOrFail();

        $this->authorize('view', $store);

        return new StoreResource($store);
    }

    #[Doc\Subgroup('Público')]
    #[Doc\Endpoint('Vitrine da loja', 'Loja `active` pelo slug, sem documento nem motivo de rejeição, com nota média e produtos ativos paginados em `products` (filtros `q`, `category`, `category_id`).')]
    #[Doc\Unauthenticated]
    #[Doc\ResponseFromApiResource(PublicStoreResource::class, Store::class, factoryStates: ['active'])]
    public function show(SearchProductsRequest $request, string $slug): PublicStoreResource
    {
        $store = Store::query()
            ->where('slug', $slug)
            ->where('status', StoreStatus::ACTIVE)
            ->withCount('productReviews')
            ->withAvg('productReviews', 'rating')
            ->firstOrFail();

        $products = $this->cursorPaginate(
            $this->productService->publicCatalogQuery([
                'store_id' => $store->id,
                'q' => $request->validated('q'),
                'category_id' => $request->validated('category_id'),
                'category' => $request->validated('category'),
            ]),
            $request,
            cursorColumn: 'id',
            descending: true,
        );

        /** @var array<string, mixed> $productsPayload */
        $productsPayload = ProductResource::collection($products)
            ->toResponse($request)
            ->getData(true);

        return (new PublicStoreResource($store))->additional([
            'products' => $productsPayload,
        ]);
    }
}
