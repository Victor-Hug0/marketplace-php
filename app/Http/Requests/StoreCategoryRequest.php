<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Category::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'parent_id' => ['nullable', 'uuid', Rule::exists('categories', 'id')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:255', 'alpha_dash'],
            'description' => ['nullable', 'string', 'max:5000'],
            'position' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, array{description: string, example?: mixed}>
     */
    public function bodyParameters(): array
    {
        return [
            'parent_id' => [
                'description' => 'Categoria pai; vazio cria uma categoria raiz.',
                'example' => '01a159cb-4e6b-7c33-9f5a-6d7b8c9d0e1f',
            ],
            'name' => [
                'description' => 'Nome exibido.',
                'example' => 'Smartphones',
            ],
            'slug' => [
                'description' => 'Slug único; gerado a partir do nome quando omitido.',
                'example' => 'smartphones',
            ],
            'description' => [
                'description' => 'Descrição da categoria.',
                'example' => 'Celulares e acessórios.',
            ],
            'position' => [
                'description' => 'Ordem de exibição entre as irmãs (menor primeiro).',
                'example' => 1,
            ],
            'is_active' => [
                'description' => 'Categorias inativas somem do catálogo público.',
                'example' => true,
            ],
        ];
    }
}
