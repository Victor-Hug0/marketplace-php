<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ProductStatus;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Support\Pagination\CursorPaginates;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ProductController extends Controller
{
    use CursorPaginates;

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Product::query()
            ->where('status', ProductStatus::ACTIVE)
            ->with([
                'images',
                'store:id,store_name,slug',
                'category:id,name,slug',
            ]);

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->string('category_id')->value());
        }

        return ProductResource::collection(
            $this->cursorPaginate($query, $request),
        );
    }

    public function show(string $slug): ProductResource
    {
        $product = Product::query()
            ->where('slug', $slug)
            ->where('status', ProductStatus::ACTIVE)
            ->with([
                'images',
                'store:id,store_name,slug',
                'category:id,name,slug',
            ])
            ->firstOrFail();

        return new ProductResource($product);
    }
}
