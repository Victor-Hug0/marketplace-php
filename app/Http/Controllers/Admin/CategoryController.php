<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCategoryAttributeRequest;
use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryAttributeRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Http\Resources\CategoryAttributeResource;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Models\CategoryAttribute;
use App\Services\CategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Knuckles\Scribe\Attributes as Doc;

#[Doc\Group('Categorias')]
final class CategoryController extends Controller
{
    public function __construct(
        private readonly CategoryService $categoryService,
    ) {}

    #[Doc\Subgroup('Admin')]
    #[Doc\Endpoint('Árvore completa', 'Inclui categorias inativas.')]
    #[Doc\ResponseFromApiResource(CategoryResource::class, Category::class, collection: true)]
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Category::class);

        $categories = Category::query()
            ->whereNull('parent_id')
            ->with([
                'children' => fn ($query) => $query
                    ->orderBy('position')
                    ->with([
                        'children' => fn ($nested) => $nested->orderBy('position'),
                        'categoryAttributes',
                    ]),
                'categoryAttributes',
            ])
            ->orderBy('position')
            ->get();

        return CategoryResource::collection($categories);
    }

    #[Doc\Subgroup('Admin')]
    #[Doc\Endpoint('Criar categoria', 'Slug gerado a partir do nome quando não informado. Profundidade máxima de 3 níveis (422).')]
    #[Doc\ResponseFromApiResource(CategoryResource::class, Category::class, status: 201)]
    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = $this->categoryService->create(
            $request->user(),
            $request->validated(),
        );

        return (new CategoryResource($category))
            ->response()
            ->setStatusCode(201);
    }

    #[Doc\Subgroup('Admin')]
    #[Doc\Endpoint('Atualizar categoria', 'Mover para outro pai revalida ciclo e profundidade da subárvore (422).')]
    #[Doc\ResponseFromApiResource(CategoryResource::class, Category::class)]
    public function update(
        UpdateCategoryRequest $request,
        Category $category,
    ): CategoryResource {
        return new CategoryResource(
            $this->categoryService->update(
                $request->user(),
                $category,
                $request->validated(),
            ),
        );
    }

    #[Doc\Subgroup('Admin')]
    #[Doc\Endpoint('Remover categoria', 'Soft delete; bloqueado se houver subcategorias ou produtos (409).')]
    #[Doc\Response('', status: 204)]
    public function destroy(Request $request, Category $category): JsonResponse
    {
        $this->authorize('manage', $category);

        $this->categoryService->delete($request->user(), $category);

        return response()->json(null, 204);
    }

    #[Doc\Subgroup('Admin')]
    #[Doc\Endpoint('Criar atributo', '`code` único na categoria; `options` obrigatório quando `data_type` é `enum`.')]
    #[Doc\ResponseFromApiResource(CategoryAttributeResource::class, CategoryAttribute::class, status: 201)]
    public function storeAttribute(
        StoreCategoryAttributeRequest $request,
        Category $category,
    ): JsonResponse {
        $attribute = $this->categoryService->createAttribute(
            $request->user(),
            $category,
            $request->validated(),
        );

        return (new CategoryAttributeResource($attribute))
            ->response()
            ->setStatusCode(201);
    }

    #[Doc\Subgroup('Admin')]
    #[Doc\Endpoint('Atualizar atributo')]
    #[Doc\ResponseFromApiResource(CategoryAttributeResource::class, CategoryAttribute::class)]
    public function updateAttribute(
        UpdateCategoryAttributeRequest $request,
        Category $category,
        CategoryAttribute $attribute,
    ): CategoryAttributeResource {
        return new CategoryAttributeResource(
            $this->categoryService->updateAttribute(
                $request->user(),
                $category,
                $attribute,
                $request->validated(),
            ),
        );
    }

    #[Doc\Subgroup('Admin')]
    #[Doc\Endpoint('Remover atributo')]
    #[Doc\Response('', status: 204)]
    public function destroyAttribute(
        Request $request,
        Category $category,
        CategoryAttribute $attribute,
    ): JsonResponse {
        $this->authorize('manage', $category);

        $this->categoryService->deleteAttribute(
            $request->user(),
            $category,
            $attribute,
        );

        return response()->json(null, 204);
    }
}
