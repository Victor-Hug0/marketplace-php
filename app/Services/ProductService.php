<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProductStatus;
use App\Enums\RoleName;
use App\Enums\StoreStatus;
use App\Events\ProductDecided;
use App\Events\ProductSubmitted;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Offer;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ProductService
{
    private const MAX_IMAGES = 8;

    public const DETAIL_RELATIONS = ['images', 'store', 'category', 'attributeValues.categoryAttribute'];

    public function __construct(
        private readonly ProductAttributeService $attributes,
    ) {}

    /**
     * @param  array{
     *     q?: string|null,
     *     category_id?: string|null,
     *     category?: string|null,
     *     store_id?: string|null,
     *     store?: string|null,
     *     attr?: array<string, string>|null
     * }  $filters
     * @return Builder<Product>
     */
    public function publicCatalogQuery(array $filters = []): Builder
    {
        $query = Product::query()
            ->where('status', ProductStatus::ACTIVE)
            ->whereHas('store', fn (Builder $storeQuery) => $storeQuery->where('status', StoreStatus::ACTIVE))
            ->with([
                'images',
                'store:id,store_name,slug,status',
                'category:id,name,slug',
                'attributeValues.categoryAttribute',
            ]);

        $this->addFromPrice($query);

        foreach ($filters['attr'] ?? [] as $code => $rawValues) {
            $this->filterByAttribute($query, (string) $code, (string) $rawValues);
        }

        if (! empty($filters['q'])) {
            $term = trim((string) $filters['q']);
            $like = '%'.$term.'%';

            $query->where(function (Builder $builder) use ($like): void {
                $builder
                    ->where('name', 'ilike', $like)
                    ->orWhere('description', 'ilike', $like);
            });
        }

        if (! empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        } elseif (! empty($filters['category'])) {
            $query->whereHas(
                'category',
                fn (Builder $categoryQuery) => $categoryQuery
                    ->where('slug', $filters['category'])
                    ->where('is_active', true),
            );
        }

        if (! empty($filters['store_id'])) {
            $query->where('store_id', $filters['store_id']);
        } elseif (! empty($filters['store'])) {
            $query->whereHas(
                'store',
                fn (Builder $storeQuery) => $storeQuery
                    ->where('slug', $filters['store'])
                    ->where('status', StoreStatus::ACTIVE),
            );
        }

        return $query;
    }

    /**
     * Adds from_price_cents (lowest current price among purchasable offers, null without offers)
     * and available_offers_count, using the same eligibility as the Buy Box.
     *
     * @param  Builder<Product>  $query
     */
    private function addFromPrice(Builder $query): void
    {
        $now = now();
        $purchasable = fn () => Offer::query()->purchasable()->whereColumn('offers.product_id', 'products.id');

        $query->addSelect([
            'from_price_cents' => $purchasable()->selectRaw('MIN('.Offer::currentPriceCentsSql().')', [$now, $now]),
            'available_offers_count' => $purchasable()->selectRaw('COUNT(*)'),
        ]);
    }

    /**
     * attr[code]=v1,v2 matches products whose value for that filterable attribute is any of the values
     * (case-insensitive for text and options); several attr[] keys must all match.
     *
     * @param  Builder<Product>  $query
     */
    private function filterByAttribute(Builder $query, string $code, string $rawValues): void
    {
        $values = array_values(array_filter(array_map('trim', explode(',', $rawValues)), fn (string $value): bool => $value !== ''));

        if ($values === []) {
            return;
        }

        $numbers = array_values(array_filter($values, 'is_numeric'));
        $booleans = array_values(array_unique(array_filter(
            array_map(fn (string $value): ?bool => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE), $values),
            fn (?bool $value): bool => $value !== null,
        )));

        $query->whereHas('attributeValues', function (Builder $value) use ($code, $values, $numbers, $booleans): void {
            $value
                ->whereHas('categoryAttribute', fn (Builder $attribute) => $attribute
                    ->where('code', $code)
                    ->where('is_filterable', true))
                ->where(function (Builder $match) use ($values, $numbers, $booleans): void {
                    $match->whereIn(DB::raw('lower(value_string)'), array_map('mb_strtolower', $values));

                    if ($numbers !== []) {
                        $match->orWhereIn('value_number', $numbers);
                    }

                    if ($booleans !== []) {
                        $match->orWhereIn('value_boolean', $booleans);
                    }
                });
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, Store $store, array $data): Product
    {
        $this->assertSellerCanWrite($actor, $store);
        $category = $this->resolveActiveCategory($data['category_id']);

        return DB::transaction(function () use ($actor, $store, $category, $data): Product {
            $product = Product::query()->create([
                'store_id' => $store->id,
                'category_id' => $category->id,
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($data['name'], $data['slug'] ?? null),
                'description' => $data['description'] ?? null,
                'weight_grams' => $data['weight_grams'] ?? null,
                'length_cm' => $data['length_cm'] ?? null,
                'width_cm' => $data['width_cm'] ?? null,
                'height_cm' => $data['height_cm'] ?? null,
                'status' => ProductStatus::DRAFT,
            ]);

            $this->attributes->apply($product, $data['attributes'] ?? []);

            $this->audit($actor, $product, 'product.created', [
                'name' => $product->name,
                'slug' => $product->slug,
                'store_id' => $store->id,
            ]);

            return $product->load(self::DETAIL_RELATIONS);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, Product $product, array $data, bool $asAdmin = false): Product
    {
        return DB::transaction(function () use ($actor, $product, $data, $asAdmin): Product {
            $product = $this->lockedProduct($product);

            if ($asAdmin) {
                $this->assertAdmin($actor);
            } else {
                $this->assertSellerOwnsProduct($actor, $product);
                $this->assertSellerCanWrite($actor, $product->store);
                $this->assertSellerCanEditContent($product);
            }

            if (array_key_exists('category_id', $data)) {
                $category = $this->resolveActiveCategory($data['category_id']);
                $data['category_id'] = $category->id;
            }

            if (array_key_exists('name', $data) && ! array_key_exists('slug', $data)) {
                $data['slug'] = $this->uniqueSlug($data['name'], null, $product->id);
            } elseif (array_key_exists('slug', $data)) {
                $data['slug'] = $this->uniqueSlug(
                    (string) ($data['name'] ?? $product->name),
                    $data['slug'],
                    $product->id,
                );
            }

            $product->update(array_intersect_key($data, array_flip([
                'category_id',
                'name',
                'slug',
                'description',
                'weight_grams',
                'length_cm',
                'width_cm',
                'height_cm',
            ])));

            if ($product->wasChanged('category_id')) {
                $this->attributes->pruneOutsideCategory($product);
            }

            $this->attributes->apply($product, $data['attributes'] ?? []);

            $this->audit($actor, $product, 'product.updated', [
                'name' => $product->name,
                'slug' => $product->slug,
            ]);

            return $product->refresh()->load(self::DETAIL_RELATIONS);
        });
    }

    public function submitForModeration(User $actor, Product $product): Product
    {
        return DB::transaction(function () use ($actor, $product): Product {
            $product = $this->lockedProduct($product);
            $this->assertSellerOwnsProduct($actor, $product);
            $this->assertSellerCanWrite($actor, $product->store);
            $this->ensureStatus($product, [
                ProductStatus::DRAFT,
                ProductStatus::REJECTED,
            ]);
            $this->attributes->assertRequiredFilled($product);

            $product->update([
                'status' => ProductStatus::PENDING_MODERATION,
                'rejection_reason' => null,
            ]);

            event(new ProductSubmitted($product));
            $this->audit($actor, $product, 'product.submitted');

            return $product->refresh()->load(self::DETAIL_RELATIONS);
        });
    }

    public function approve(User $admin, Product $product): Product
    {
        return DB::transaction(function () use ($admin, $product): Product {
            $this->assertAdmin($admin);
            $product = $this->lockedProduct($product);
            $this->ensureStatus($product, [ProductStatus::PENDING_MODERATION]);

            $product->update([
                'status' => ProductStatus::ACTIVE,
                'rejection_reason' => null,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]);

            event(new ProductDecided($product));
            $this->audit($admin, $product, 'product.approved');

            return $product->refresh()->load(self::DETAIL_RELATIONS);
        });
    }

    public function reject(User $admin, Product $product, string $reason): Product
    {
        return DB::transaction(function () use ($admin, $product, $reason): Product {
            $this->assertAdmin($admin);
            $product = $this->lockedProduct($product);
            $this->ensureStatus($product, [ProductStatus::PENDING_MODERATION]);

            $product->update([
                'status' => ProductStatus::REJECTED,
                'rejection_reason' => $reason,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]);

            event(new ProductDecided($product));
            $this->audit($admin, $product, 'product.rejected', ['reason' => $reason]);

            return $product->refresh()->load(self::DETAIL_RELATIONS);
        });
    }

    public function pause(User $actor, Product $product, bool $asAdmin = false): Product
    {
        return DB::transaction(function () use ($actor, $product, $asAdmin): Product {
            $product = $this->lockedProduct($product);

            if ($asAdmin) {
                $this->assertAdmin($actor);
            } else {
                $this->assertSellerOwnsProduct($actor, $product);
                $this->assertSellerCanWrite($actor, $product->store);
            }

            $this->ensureStatus($product, [ProductStatus::ACTIVE]);

            $product->update([
                'status' => ProductStatus::PAUSED,
            ]);

            $this->audit($actor, $product, 'product.paused');

            return $product->refresh()->load(self::DETAIL_RELATIONS);
        });
    }

    public function resume(User $actor, Product $product): Product
    {
        return DB::transaction(function () use ($actor, $product): Product {
            $product = $this->lockedProduct($product);
            $this->assertSellerOwnsProduct($actor, $product);
            $this->assertSellerCanWrite($actor, $product->store);
            $this->ensureStatus($product, [ProductStatus::PAUSED]);

            $product->update([
                'status' => ProductStatus::ACTIVE,
            ]);

            $this->audit($actor, $product, 'product.resumed');

            return $product->refresh()->load(self::DETAIL_RELATIONS);
        });
    }

    public function delete(User $actor, Product $product, bool $asAdmin = false): void
    {
        DB::transaction(function () use ($actor, $product, $asAdmin): void {
            $product = $this->lockedProduct($product);

            if ($asAdmin) {
                $this->assertAdmin($actor);
            } else {
                $this->assertSellerOwnsProduct($actor, $product);
                $this->assertSellerCanWrite($actor, $product->store);
                $this->ensureStatus($product, [
                    ProductStatus::DRAFT,
                    ProductStatus::REJECTED,
                    ProductStatus::PAUSED,
                ]);
            }

            $imagePaths = $product->images()->pluck('path')->all();

            $product->images()->delete();
            $product->delete();

            foreach ($imagePaths as $path) {
                Storage::disk('public')->delete($path);
            }

            $this->audit($actor, $product, 'product.deleted', [
                'name' => $product->name,
                'slug' => $product->slug,
            ]);
        });
    }

    public function addImage(
        User $actor,
        Product $product,
        UploadedFile $file,
        ?string $alt = null,
        bool $asAdmin = false,
    ): ProductImage {
        return DB::transaction(function () use ($actor, $product, $file, $alt, $asAdmin): ProductImage {
            $product = $this->lockedProduct($product);

            if ($asAdmin) {
                $this->assertAdmin($actor);
            } else {
                $this->assertSellerOwnsProduct($actor, $product);
                $this->assertSellerCanWrite($actor, $product->store);
                $this->assertSellerCanEditContent($product);
            }

            $count = $product->images()->count();
            if ($count >= self::MAX_IMAGES) {
                throw new ConflictHttpException(
                    'O produto já atingiu o limite de '.self::MAX_IMAGES.' imagens.',
                );
            }

            $path = $file->store("products/{$product->id}", 'public');

            $image = $product->images()->create([
                'path' => $path,
                'position' => $count,
                'alt' => $alt,
            ]);

            $this->audit($actor, $product, 'product.image.created', [
                'image_id' => $image->id,
                'position' => $image->position,
            ]);

            return $image;
        });
    }

    public function deleteImage(
        User $actor,
        Product $product,
        ProductImage $image,
        bool $asAdmin = false,
    ): void {
        DB::transaction(function () use ($actor, $product, $image, $asAdmin): void {
            $product = $this->lockedProduct($product);

            if ($asAdmin) {
                $this->assertAdmin($actor);
            } else {
                $this->assertSellerOwnsProduct($actor, $product);
                $this->assertSellerCanWrite($actor, $product->store);
                $this->assertSellerCanEditContent($product);
            }

            if ($image->product_id !== $product->id) {
                throw new NotFoundHttpException('Imagem não encontrada para este produto.');
            }

            $path = $image->path;
            $image->delete();

            Storage::disk('public')->delete($path);
            $this->reindexPositions($product);

            $this->audit($actor, $product, 'product.image.deleted', [
                'image_id' => $image->id,
            ]);
        });
    }

    /**
     * @param  list<string>  $imageIds
     */
    public function reorderImages(
        User $actor,
        Product $product,
        array $imageIds,
        bool $asAdmin = false,
    ): Product {
        return DB::transaction(function () use ($actor, $product, $imageIds, $asAdmin): Product {
            $product = $this->lockedProduct($product);

            if ($asAdmin) {
                $this->assertAdmin($actor);
            } else {
                $this->assertSellerOwnsProduct($actor, $product);
                $this->assertSellerCanWrite($actor, $product->store);
                $this->assertSellerCanEditContent($product);
            }

            $existingIds = $product->images()->pluck('id')->all();
            sort($existingIds);
            $sortedIncoming = $imageIds;
            sort($sortedIncoming);

            if ($existingIds !== $sortedIncoming) {
                throw new ConflictHttpException(
                    'A lista de imagens deve conter exatamente todas as imagens do produto.',
                );
            }

            foreach ($imageIds as $position => $imageId) {
                ProductImage::query()
                    ->where('product_id', $product->id)
                    ->where('id', $imageId)
                    ->update(['position' => $position]);
            }

            $this->audit($actor, $product, 'product.image.reordered', [
                'image_ids' => $imageIds,
            ]);

            return $product->refresh()->load(self::DETAIL_RELATIONS);
        });
    }

    private function lockedProduct(Product $product): Product
    {
        return Product::query()
            ->with(['store', 'images'])
            ->lockForUpdate()
            ->findOrFail($product->id);
    }

    /**
     * @param  list<ProductStatus>  $expected
     */
    private function ensureStatus(Product $product, array $expected): void
    {
        if (! in_array($product->status, $expected, true)) {
            $allowed = implode(', ', array_map(
                static fn (ProductStatus $status): string => $status->value,
                $expected,
            ));

            throw new ConflictHttpException(
                "O produto precisa estar com status {$allowed} para executar esta ação.",
            );
        }
    }

    private function assertSellerCanEditContent(Product $product): void
    {
        if (! $product->isEditableBySeller()) {
            throw new ConflictHttpException(
                'O produto só pode ser editado enquanto estiver em draft ou rejected.',
            );
        }
    }

    private function assertSellerOwnsProduct(User $actor, Product $product): void
    {
        if ($product->store->user_id !== $actor->id) {
            throw new AccessDeniedHttpException('Usuário não autorizado.');
        }
    }

    private function assertSellerCanWrite(User $actor, Store $store): void
    {
        if (! $actor->hasRole(RoleName::SELLER)) {
            throw new AccessDeniedHttpException('Usuário não autorizado.');
        }

        if ($store->user_id !== $actor->id || $store->status !== StoreStatus::ACTIVE) {
            throw new AccessDeniedHttpException(
                'Somente sellers com loja ativa podem gerenciar produtos.',
            );
        }
    }

    private function assertAdmin(User $actor): void
    {
        if (! $actor->hasRole(RoleName::ADMIN)) {
            throw new AccessDeniedHttpException('Usuário não autorizado.');
        }
    }

    private function resolveActiveCategory(string $categoryId): Category
    {
        $category = Category::query()
            ->whereKey($categoryId)
            ->where('is_active', true)
            ->first();

        if ($category === null) {
            throw new ConflictHttpException('A categoria informada é inválida ou está inativa.');
        }

        return $category;
    }

    private function uniqueSlug(string $name, ?string $slug = null, ?string $ignoreId = null): string
    {
        $base = Str::slug($slug ?: $name) ?: 'produto';
        $candidate = $base;
        $suffix = 2;

        while (
            Product::withTrashed()
                ->where('slug', $candidate)
                ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $candidate = "{$base}-{$suffix}";
            $suffix++;
        }

        return $candidate;
    }

    private function reindexPositions(Product $product): void
    {
        $images = $product->images()->orderBy('position')->get();

        foreach ($images as $position => $image) {
            if ($image->position !== $position) {
                $image->update(['position' => $position]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function audit(User $actor, Product $product, string $action, array $payload = []): void
    {
        AuditLog::query()->create([
            'action' => $action,
            'actor_id' => $actor->id,
            'actor_type' => $actor->getMorphClass(),
            'auditable_id' => $product->id,
            'auditable_type' => $product->getMorphClass(),
            'payload' => $payload,
            'created_at' => now(),
        ]);
    }
}
