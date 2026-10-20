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
use Knuckles\Scribe\Attributes as Doc;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Doc\Group('Produtos')]
final class ProductController extends Controller
{
    use CursorPaginates;

    public function __construct(
        private readonly ProductService $productService,
    ) {}

    #[Doc\Subgroup('Seller')]
    #[Doc\Endpoint('Meus produtos', 'Produtos da própria loja, em qualquer status.')]
    #[Doc\ResponseFromApiResource(ProductResource::class, Product::class, collection: true, cursorPaginate: 20)]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Product::class);

        $store = $request->user()->store;
        abort_if($store === null, 403, 'Usuário não autorizado.');

        $products = $this->cursorPaginate(
            Product::query()
                ->where('store_id', $store->id)
                ->with(['images', 'store:id,store_name,slug', 'category:id,name,slug', 'attributeValues.categoryAttribute']),
            $request,
        );

        return ProductResource::collection($products);
    }

    #[Doc\Subgroup('Seller')]
    #[Doc\Endpoint('Criar produto', 'Cria em `draft`. Exige loja `active`. Peso e medidas são usados na cotação de frete.')]
    #[Doc\ResponseFromApiResource(ProductResource::class, Product::class, status: 201)]
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

    #[Doc\Subgroup('Seller')]
    #[Doc\Endpoint('Detalhar meu produto')]
    #[Doc\ResponseFromApiResource(ProductResource::class, Product::class, with: ['images', 'category'])]
    public function show(Request $request, Product $product): ProductResource
    {
        $this->ensureSellerProduct($request, $product);
        $this->authorize('view', $product);

        $product->load(['images', 'store:id,store_name,slug', 'category:id,name,slug', 'attributeValues.categoryAttribute']);

        return new ProductResource($product);
    }

    #[Doc\Subgroup('Seller')]
    #[Doc\Endpoint('Editar produto', 'Só em `draft` ou `rejected` (409 nos demais).')]
    #[Doc\ResponseFromApiResource(ProductResource::class, Product::class)]
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

    #[Doc\Subgroup('Seller')]
    #[Doc\Endpoint('Remover produto', 'Soft delete em `draft`, `rejected` ou `paused`; apaga as imagens do disco.')]
    #[Doc\Response('', status: 204)]
    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->ensureSellerProduct($request, $product);
        $this->authorize('delete', $product);

        $this->productService->delete($request->user(), $product);

        return response()->json(null, 204);
    }

    #[Doc\Subgroup('Seller')]
    #[Doc\Endpoint('Enviar para moderação', '`draft` ou `rejected` → `pending_moderation`.')]
    #[Doc\ResponseFromApiResource(ProductResource::class, Product::class, factoryStates: ['pendingModeration'])]
    public function submit(Request $request, Product $product): ProductResource
    {
        $this->ensureSellerProduct($request, $product);
        $this->authorize('submit', $product);

        return new ProductResource(
            $this->productService->submitForModeration($request->user(), $product),
        );
    }

    #[Doc\Subgroup('Seller')]
    #[Doc\Endpoint('Pausar produto', '`active → paused`; as ofertas saem da compra.')]
    #[Doc\ResponseFromApiResource(ProductResource::class, Product::class, factoryStates: ['paused'])]
    public function pause(Request $request, Product $product): ProductResource
    {
        $this->ensureSellerProduct($request, $product);
        $this->authorize('pause', $product);

        return new ProductResource(
            $this->productService->pause($request->user(), $product),
        );
    }

    #[Doc\Subgroup('Seller')]
    #[Doc\Endpoint('Retomar produto', '`paused → active`.')]
    #[Doc\ResponseFromApiResource(ProductResource::class, Product::class, factoryStates: ['active'])]
    public function resume(Request $request, Product $product): ProductResource
    {
        $this->ensureSellerProduct($request, $product);
        $this->authorize('resume', $product);

        return new ProductResource(
            $this->productService->resume($request->user(), $product),
        );
    }

    #[Doc\Subgroup('Seller')]
    #[Doc\Endpoint('Enviar imagem', 'multipart/form-data. jpeg, jpg, png ou webp até 2 MB; no máximo 8 imagens (409). A primeira vira capa.')]
    #[Doc\ResponseFromApiResource(ProductImageResource::class, ProductImage::class, status: 201)]
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

    #[Doc\Subgroup('Seller')]
    #[Doc\Endpoint('Remover imagem')]
    #[Doc\Response('', status: 204)]
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

    #[Doc\Subgroup('Seller')]
    #[Doc\Endpoint('Reordenar imagens', '`image_ids` precisa listar todas as imagens do produto, na nova ordem (409 se não listar).')]
    #[Doc\ResponseFromApiResource(ProductResource::class, Product::class, with: ['images'])]
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
