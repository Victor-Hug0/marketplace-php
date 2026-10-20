<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SearchProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'category_id' => ['sometimes', 'nullable', 'uuid'],
            'category' => ['sometimes', 'nullable', 'string', 'max:255'],
            'store_id' => ['sometimes', 'nullable', 'uuid'],
            'store' => ['sometimes', 'nullable', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'nullable', 'string'],
        ];
    }

    /**
     * @return array<string, array{description: string, example?: mixed}>
     */
    public function queryParameters(): array
    {
        return [
            'q' => [
                'description' => 'Texto buscado no nome e na descrição.',
                'example' => 'smartphone',
            ],
            'category_id' => [
                'description' => 'Filtra pela categoria exata (sem subcategorias).',
                'example' => '01a159cb-4e6b-7c33-9f5a-6d7b8c9d0e1f',
            ],
            'category' => [
                'description' => 'Filtra pelo slug de uma categoria ativa; ignorado com category_id.',
                'example' => 'smartphones',
            ],
            'store_id' => [
                'description' => 'Filtra pela loja dona do produto.',
                'example' => '01a159cb-5f7c-7d44-a06b-7e8c9d0e1f2a',
            ],
            'store' => [
                'description' => 'Filtra pelo slug da loja dona do produto; ignorado com store_id.',
                'example' => 'loja-demo',
            ],
            'per_page' => [
                'description' => 'Itens por página (1 a 100).',
                'example' => 20,
            ],
            'cursor' => [
                'description' => 'Cursor devolvido em meta.next_cursor.',
            ],
        ];
    }
}
