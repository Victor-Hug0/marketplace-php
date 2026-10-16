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

final class StoreController extends Controller
{
    use CursorPaginates;

    public function __construct(
        private readonly StoreService $storeService,
        private readonly ProductService $productService,
    ) {}

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

    public function me(Request $request): StoreResource
    {
        $store = Store::query()
            ->whereBelongsTo($request->user(), 'owner')
            ->firstOrFail();

        $this->authorize('view', $store);

        return new StoreResource($store);
    }

    public function show(SearchProductsRequest $request, string $slug): PublicStoreResource
    {
        $store = Store::query()
            ->where('slug', $slug)
            ->where('status', StoreStatus::ACTIVE)
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
