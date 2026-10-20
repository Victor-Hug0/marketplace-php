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

final class ProductReviewController extends Controller
{
    use CursorPaginates;

    public function __construct(
        private readonly ProductReviewService $productReviewService,
    ) {}

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
