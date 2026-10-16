<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\RejectProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\ProductService;
use App\Support\Pagination\CursorPaginates;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

final class ProductController extends Controller
{
    use CursorPaginates;

    public function __construct(
        private readonly ProductService $productService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Product::class);

        $request->validate([
            'status' => ['sometimes', 'string', Rule::enum(ProductStatus::class)],
        ]);

        $query = Product::query()
            ->with(['images', 'store:id,store_name,slug', 'category:id,name,slug']);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->value());
        }

        return ProductResource::collection(
            $this->cursorPaginate($query, $request),
        );
    }

    public function show(Product $product): ProductResource
    {
        $this->authorize('view', $product);

        $product->load(['images', 'store:id,store_name,slug', 'category:id,name,slug']);

        return new ProductResource($product);
    }

    public function update(UpdateProductRequest $request, Product $product): ProductResource
    {
        return new ProductResource(
            $this->productService->update(
                $request->user(),
                $product,
                $request->validated(),
                asAdmin: true,
            ),
        );
    }

    public function approve(Request $request, Product $product): ProductResource
    {
        $this->authorize('review', $product);

        return new ProductResource(
            $this->productService->approve($request->user(), $product),
        );
    }

    public function reject(RejectProductRequest $request, Product $product): ProductResource
    {
        return new ProductResource(
            $this->productService->reject(
                $request->user(),
                $product,
                $request->string('reason')->value(),
            ),
        );
    }

    public function pause(Request $request, Product $product): ProductResource
    {
        $this->authorize('pause', $product);

        return new ProductResource(
            $this->productService->pause($request->user(), $product, asAdmin: true),
        );
    }
}
