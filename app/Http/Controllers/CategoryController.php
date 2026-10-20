<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Knuckles\Scribe\Attributes as Doc;

#[Doc\Group('Categorias')]
final class CategoryController extends Controller
{
    #[Doc\Subgroup('Público')]
    #[Doc\Endpoint('Árvore de categorias', 'Categorias ativas em árvore (`children`), até 3 níveis.')]
    #[Doc\Unauthenticated]
    #[Doc\ResponseFromApiResource(CategoryResource::class, Category::class, collection: true)]
    public function index(): AnonymousResourceCollection
    {
        $categories = Category::query()
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->with([
                'children' => fn ($query) => $query
                    ->where('is_active', true)
                    ->orderBy('position')
                    ->with([
                        'children' => fn ($nested) => $nested
                            ->where('is_active', true)
                            ->orderBy('position'),
                    ]),
            ])
            ->orderBy('position')
            ->get();

        return CategoryResource::collection($categories);
    }

    #[Doc\Subgroup('Público')]
    #[Doc\Endpoint('Detalhar categoria', 'Categoria ativa pelo slug, com atributos.')]
    #[Doc\Unauthenticated]
    #[Doc\ResponseFromApiResource(CategoryResource::class, Category::class, with: ['categoryAttributes'])]
    public function show(string $slug): CategoryResource
    {
        $category = Category::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->with(['categoryAttributes'])
            ->firstOrFail();

        return new CategoryResource($category);
    }
}
