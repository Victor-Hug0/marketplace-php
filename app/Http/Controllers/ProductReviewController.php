<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ProductStatus;
use App\Enums\StoreStatus;
use App\Http\Requests\StoreProductReviewRequest;
use App\Http\Resources\ProductReviewResource;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductReview;
use App\Services\ProductReviewService;
use App\Support\Pagination\CursorPaginates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Knuckles\Scribe\Attributes as Doc;

#[Doc\Group('Avaliações')]
final class ProductReviewController extends Controller
{
    use CursorPaginates;

    public function __construct(
        private readonly ProductReviewService $productReviewService,
    ) {}

    #[Doc\Subgroup('Público')]
    #[Doc\Endpoint('Avaliações do produto', 'Autor só com o primeiro nome; `summary` traz média e quantidade.')]
    #[Doc\Unauthenticated]
    #[Doc\ResponseFromApiResource(ProductReviewResource::class, ProductReview::class, collection: true, cursorPaginate: 20, with: ['author'], additional: ['summary' => ['average' => 4.5, 'count' => 2]])]
    public function index(Request $request, string $slug): AnonymousResourceCollection
    {
        $product = Product::query()
            ->where('slug', $slug)
            ->where('status', ProductStatus::ACTIVE)
            ->whereHas('store', fn ($query) => $query->where('status', StoreStatus::ACTIVE))
            ->firstOrFail();

        $reviews = $this->cursorPaginate(
            ProductReview::query()
                ->where('product_id', $product->id)
                ->with('author:id,name'),
            $request,
            cursorColumn: 'id',
            descending: true,
        );

        return ProductReviewResource::collection($reviews)->additional([
            'summary' => $this->productReviewService->productSummary($product->id),
        ]);
    }

    #[Doc\Subgroup('Comprador')]
    #[Doc\Endpoint('Avaliar item', 'Só o comprador do item, com o subpedido `delivered`, uma vez por item; o dono da loja não avalia o próprio produto (403).')]
    #[Doc\ResponseFromApiResource(ProductReviewResource::class, ProductReview::class, status: 201, with: ['author'])]
    public function store(StoreProductReviewRequest $request, OrderItem $orderItem): JsonResponse
    {
        $review = $this->productReviewService->create(
            $request->user(),
            $orderItem,
            $request->validated(),
        );

        return (new ProductReviewResource($review))
            ->additional(['message' => 'Avaliação registrada com sucesso.'])
            ->response()
            ->setStatusCode(201);
    }
}
