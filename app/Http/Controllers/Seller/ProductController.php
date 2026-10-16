<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReorderProductImagesRequest;
use App\Http\Requests\StoreProductImageRequest;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductImageResource;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\ProductService;
use App\Support\Pagination\CursorPaginates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ProductController extends Controller
{
    use CursorPaginates;

    public function __construct(
        private readonly ProductService $productService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Product::class);

        $store = $request->user()->store;
        abort_if($store === null, 403, 'Usuário não autorizado.');

        $products = $this->cursorPaginate(
            Product::query()
                ->where('store_id', $store->id)
                ->with(['images', 'store:id,store_name,slug', 'category:id,name,slug']),
            $request,
        );

        return ProductResource::collection($products);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $store = $request->user()->store;
        abort_if($store === null, 403, 'Usuário não autorizado.');

        $product = $this->productService->create(
            $request->user(),
            $store,
            $request->validated(),
        );

        return (new ProductResource($product))
            ->additional(['message' => 'Produto criado como rascunho.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Product $product): ProductResource
    {
        $this->ensureSellerProduct($request, $product);
        $this->authorize('view', $product);

        $product->load(['images', 'store:id,store_name,slug', 'category:id,name,slug']);

        return new ProductResource($product);
    }

    public function update(UpdateProductRequest $request, Product $product): ProductResource
    {
        $this->ensureSellerProduct($request, $product);

        return new ProductResource(
            $this->productService->update(
                $request->user(),
                $product,
                $request->validated(),
            ),
        );
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->ensureSellerProduct($request, $product);
        $this->authorize('delete', $product);

        $this->productService->delete($request->user(), $product);

        return response()->json(null, 204);
    }

    public function submit(Request $request, Product $product): ProductResource
    {
        $this->ensureSellerProduct($request, $product);
        $this->authorize('submit', $product);

        return new ProductResource(
            $this->productService->submitForModeration($request->user(), $product),
        );
    }

    public function pause(Request $request, Product $product): ProductResource
    {
        $this->ensureSellerProduct($request, $product);
        $this->authorize('pause', $product);

        return new ProductResource(
            $this->productService->pause($request->user(), $product),
        );
    }

    public function resume(Request $request, Product $product): ProductResource
    {
        $this->ensureSellerProduct($request, $product);
        $this->authorize('resume', $product);

        return new ProductResource(
            $this->productService->resume($request->user(), $product),
        );
    }

    public function storeImage(
        StoreProductImageRequest $request,
        Product $product,
    ): JsonResponse {
        $this->ensureSellerProduct($request, $product);

        $image = $this->productService->addImage(
            $request->user(),
            $product,
            $request->file('image'),
            $request->validated('alt'),
        );

        return (new ProductImageResource($image))
            ->additional(['message' => 'Imagem adicionada com sucesso.'])
            ->response()
            ->setStatusCode(201);
    }

    public function destroyImage(
        Request $request,
        Product $product,
        ProductImage $image,
    ): JsonResponse {
        $this->ensureSellerProduct($request, $product);
        $this->authorize('manageImages', $product);

        if ($image->product_id !== $product->id) {
            throw new NotFoundHttpException('Imagem não encontrada para este produto.');
        }

        $this->productService->deleteImage($request->user(), $product, $image);

        return response()->json(null, 204);
    }

    public function reorderImages(
        ReorderProductImagesRequest $request,
        Product $product,
    ): ProductResource {
        $this->ensureSellerProduct($request, $product);

        return new ProductResource(
            $this->productService->reorderImages(
                $request->user(),
                $product,
                $request->validated('image_ids'),
            ),
        );
    }

    private function ensureSellerProduct(Request $request, Product $product): void
    {
        $store = $request->user()?->store;

        if ($store === null || $product->store_id !== $store->id) {
            throw new NotFoundHttpException('Recurso não encontrado.');
        }
    }
}
