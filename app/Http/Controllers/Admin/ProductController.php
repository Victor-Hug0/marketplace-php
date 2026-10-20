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
use Knuckles\Scribe\Attributes as Doc;

#[Doc\Group('Produtos')]
final class ProductController extends Controller
{
    use CursorPaginates;

    public function __construct(
        private readonly ProductService $productService,
    ) {}

    #[Doc\Subgroup('Admin')]
    #[Doc\Endpoint('Listar produtos', 'Filtro opcional por `status`.')]
    #[Doc\ResponseFromApiResource(ProductResource::class, Product::class, collection: true, cursorPaginate: 20)]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Product::class);

        $request->validate([
            'status' => ['sometimes', 'string', Rule::enum(ProductStatus::class)],
        ]);

        $query = Product::query()
            ->with(['images', 'store:id,store_name,slug', 'category:id,name,slug', 'attributeValues.categoryAttribute']);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->value());
        }

        return ProductResource::collection(
            $this->cursorPaginate($query, $request),
        );
    }

    #[Doc\Subgroup('Admin')]
    #[Doc\Endpoint('Detalhar produto (admin)')]
    #[Doc\ResponseFromApiResource(ProductResource::class, Product::class, with: ['store', 'category', 'images'])]
    public function show(Product $product): ProductResource
    {
        $this->authorize('view', $product);

        $product->load(['images', 'store:id,store_name,slug', 'category:id,name,slug', 'attributeValues.categoryAttribute']);

        return new ProductResource($product);
    }

    #[Doc\Subgroup('Admin')]
    #[Doc\Endpoint('Editar produto (admin)', 'O admin edita em qualquer status.')]
    #[Doc\ResponseFromApiResource(ProductResource::class, Product::class)]
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

    #[Doc\Subgroup('Admin')]
    #[Doc\Endpoint('Aprovar produto', '`pending_moderation → active`.')]
    #[Doc\ResponseFromApiResource(ProductResource::class, Product::class, factoryStates: ['active'])]
    public function approve(Request $request, Product $product): ProductResource
    {
        $this->authorize('review', $product);

        return new ProductResource(
            $this->productService->approve($request->user(), $product),
        );
    }

    #[Doc\Subgroup('Admin')]
    #[Doc\Endpoint('Rejeitar produto', '`pending_moderation → rejected` com motivo.')]
    #[Doc\ResponseFromApiResource(ProductResource::class, Product::class, factoryStates: ['rejected'])]
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

    #[Doc\Subgroup('Admin')]
    #[Doc\Endpoint('Pausar produto (admin)', '`active → paused`.')]
    #[Doc\ResponseFromApiResource(ProductResource::class, Product::class, factoryStates: ['paused'])]
    public function pause(Request $request, Product $product): ProductResource
    {
        $this->authorize('pause', $product);

        return new ProductResource(
            $this->productService->pause($request->user(), $product, asAdmin: true),
        );
    }
}
