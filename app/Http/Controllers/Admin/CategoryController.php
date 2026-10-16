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

final class CategoryController extends Controller
{
    public function __construct(
        private readonly CategoryService $categoryService,
    ) {}

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

    public function destroy(Request $request, Category $category): JsonResponse
    {
        $this->authorize('manage', $category);

        $this->categoryService->delete($request->user(), $category);

        return response()->json(null, 204);
    }

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
