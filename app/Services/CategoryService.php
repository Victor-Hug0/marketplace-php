<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CategoryAttributeDataType;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\CategoryAttribute;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class CategoryService
{
    private const MAX_DEPTH = 3;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data): Category
    {
        return DB::transaction(function () use ($actor, $data): Category {
            $parent = $this->resolveParent($data['parent_id'] ?? null);
            $this->assertDepthAllowed($parent);

            $category = Category::query()->create([
                'parent_id' => $parent?->id,
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($data['name'], $data['slug'] ?? null),
                'description' => $data['description'] ?? null,
                'position' => $data['position'] ?? 0,
                'is_active' => $data['is_active'] ?? true,
            ]);

            $this->audit($actor, $category, 'category.created', [
                'name' => $category->name,
                'slug' => $category->slug,
            ]);

            return $category;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, Category $category, array $data): Category
    {
        return DB::transaction(function () use ($actor, $category, $data): Category {
            $category = Category::query()->lockForUpdate()->findOrFail($category->id);

            $parentId = array_key_exists('parent_id', $data)
                ? $data['parent_id']
                : $category->parent_id;

            if ($parentId === $category->id) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Uma categoria não pode ser pai de si mesma.',
                ]);
            }

            $parent = $this->resolveParent($parentId);

            if ($parent !== null) {
                $this->assertNotDescendant($category, $parent);
                $this->assertDepthAllowed($parent, $this->subtreeHeight($category));
            }

            $name = $data['name'] ?? $category->name;
            $slug = array_key_exists('slug', $data)
                ? $this->uniqueSlug($name, $data['slug'], $category->id)
                : (array_key_exists('name', $data)
                    ? $this->uniqueSlug($name, null, $category->id)
                    : $category->slug);

            $category->update([
                'parent_id' => $parent?->id,
                'name' => $name,
                'slug' => $slug,
                'description' => array_key_exists('description', $data)
                    ? $data['description']
                    : $category->description,
                'position' => $data['position'] ?? $category->position,
                'is_active' => $data['is_active'] ?? $category->is_active,
            ]);

            $this->audit($actor, $category, 'category.updated', [
                'name' => $category->name,
                'slug' => $category->slug,
            ]);

            return $category->refresh();
        });
    }

    public function delete(User $actor, Category $category): void
    {
        DB::transaction(function () use ($actor, $category): void {
            $category = Category::query()->lockForUpdate()->findOrFail($category->id);

            if ($category->children()->exists()) {
                throw new ConflictHttpException(
                    'Não é possível remover uma categoria que possui subcategorias.',
                );
            }

            if ($category->products()->exists()) {
                throw new ConflictHttpException(
                    'Não é possível remover uma categoria que possui produtos.',
                );
            }

            $category->delete();

            $this->audit($actor, $category, 'category.deleted', [
                'name' => $category->name,
                'slug' => $category->slug,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createAttribute(
        User $actor,
        Category $category,
        array $data,
    ): CategoryAttribute {
        return DB::transaction(function () use ($actor, $category, $data): CategoryAttribute {
            $attribute = $category->categoryAttributes()->create([
                'code' => $data['code'],
                'label' => $data['label'],
                'data_type' => $data['data_type'],
                'options' => $this->normalizeOptions($data),
                'is_required' => $data['is_required'] ?? false,
                'is_filterable' => $data['is_filterable'] ?? true,
                'position' => $data['position'] ?? 0,
            ]);

            $this->audit($actor, $category, 'category.attribute.created', [
                'attribute_id' => $attribute->id,
                'code' => $attribute->code,
            ]);

            return $attribute;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateAttribute(
        User $actor,
        Category $category,
        CategoryAttribute $attribute,
        array $data,
    ): CategoryAttribute {
        return DB::transaction(function () use ($actor, $category, $attribute, $data): CategoryAttribute {
            $this->assertAttributeBelongsToCategory($category, $attribute);

            $dataType = $data['data_type'] ?? $attribute->data_type->value;
            $merged = [
                'data_type' => $dataType,
                'options' => array_key_exists('options', $data)
                    ? $data['options']
                    : $attribute->options,
            ];

            $attribute->update([
                'code' => $data['code'] ?? $attribute->code,
                'label' => $data['label'] ?? $attribute->label,
                'data_type' => $dataType,
                'options' => $this->normalizeOptions($merged),
                'is_required' => $data['is_required'] ?? $attribute->is_required,
                'is_filterable' => $data['is_filterable'] ?? $attribute->is_filterable,
                'position' => $data['position'] ?? $attribute->position,
            ]);

            $this->audit($actor, $category, 'category.attribute.updated', [
                'attribute_id' => $attribute->id,
                'code' => $attribute->code,
            ]);

            return $attribute->refresh();
        });
    }

    public function deleteAttribute(
        User $actor,
        Category $category,
        CategoryAttribute $attribute,
    ): void {
        DB::transaction(function () use ($actor, $category, $attribute): void {
            $this->assertAttributeBelongsToCategory($category, $attribute);

            $code = $attribute->code;
            $attributeId = $attribute->id;
            $attribute->delete();

            $this->audit($actor, $category, 'category.attribute.deleted', [
                'attribute_id' => $attributeId,
                'code' => $code,
            ]);
        });
    }

    private function resolveParent(mixed $parentId): ?Category
    {
        if ($parentId === null || $parentId === '') {
            return null;
        }

        return Category::query()->findOrFail($parentId);
    }

    private function assertDepthAllowed(?Category $parent, int $subtreeHeight = 1): void
    {
        $parentDepth = $parent?->depth() ?? 0;
        $resultingDepth = $parentDepth + $subtreeHeight;

        if ($resultingDepth > self::MAX_DEPTH) {
            throw ValidationException::withMessages([
                'parent_id' => 'A árvore de categorias permite no máximo '.self::MAX_DEPTH.' níveis.',
            ]);
        }
    }

    private function assertNotDescendant(Category $category, Category $candidateParent): void
    {
        $current = $candidateParent;

        while ($current !== null) {
            if ($current->id === $category->id) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Não é permitido criar um ciclo na árvore de categorias.',
                ]);
            }

            $current = $current->parent;
        }
    }

    private function subtreeHeight(Category $category): int
    {
        $maxChildHeight = 0;

        foreach ($category->children as $child) {
            $maxChildHeight = max($maxChildHeight, $this->subtreeHeight($child));
        }

        return 1 + $maxChildHeight;
    }

    private function uniqueSlug(string $name, ?string $slug = null, ?string $ignoreId = null): string
    {
        $base = Str::slug($slug ?: $name) ?: 'categoria';
        $candidate = $base;
        $suffix = 2;

        while (
            Category::withTrashed()
                ->where('slug', $candidate)
                ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $candidate = "{$base}-{$suffix}";
            $suffix++;
        }

        return $candidate;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>|null
     */
    private function normalizeOptions(array $data): ?array
    {
        $dataType = $data['data_type'] instanceof CategoryAttributeDataType
            ? $data['data_type']
            : CategoryAttributeDataType::from((string) $data['data_type']);

        if ($dataType !== CategoryAttributeDataType::ENUM) {
            return null;
        }

        $options = $data['options'] ?? null;

        if (! is_array($options) || $options === []) {
            throw ValidationException::withMessages([
                'options' => 'Atributos do tipo enum exigem uma lista de opções.',
            ]);
        }

        return array_values(array_map(
            static fn (mixed $option): string => (string) $option,
            $options,
        ));
    }

    private function assertAttributeBelongsToCategory(
        Category $category,
        CategoryAttribute $attribute,
    ): void {
        if ($attribute->category_id !== $category->id) {
            throw ValidationException::withMessages([
                'attribute' => 'O atributo não pertence a esta categoria.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function audit(
        User $actor,
        Category $category,
        string $action,
        array $payload = [],
    ): void {
        AuditLog::query()->create([
            'action' => $action,
            'actor_id' => $actor->id,
            'actor_type' => $actor->getMorphClass(),
            'auditable_id' => $category->id,
            'auditable_type' => $category->getMorphClass(),
            'payload' => $payload,
            'created_at' => now(),
        ]);
    }
}
