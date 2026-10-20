<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Product::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'category_id' => [
                'required',
                'uuid',
                Rule::exists('categories', 'id')->where(fn ($query) => $query
                    ->where('is_active', true)
                    ->whereNull('deleted_at')),
            ],
            'name' => ['required', 'string', 'min:3', 'max:180'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:180'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'weight_grams' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'length_cm' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:300'],
            'width_cm' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:300'],
            'height_cm' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:300'],
            'attributes' => ['sometimes', 'array', 'max:50'],
            'attributes.*' => ['nullable'],
        ];
    }

    /**
     * @return array<string, array{description: string, example?: mixed}>
     */
    public function bodyParameters(): array
    {
        return [
            'category_id' => [
                'description' => 'Categoria ativa do produto.',
                'example' => '01a159cb-4e6b-7c33-9f5a-6d7b8c9d0e1f',
            ],
            'name' => [
                'description' => 'Nome do produto.',
                'example' => 'Smartphone Demo X 128 GB',
            ],
            'slug' => [
                'description' => 'Slug único; gerado a partir do nome quando omitido.',
                'example' => 'smartphone-demo-x-128-gb',
            ],
            'description' => [
                'description' => 'Descrição longa.',
                'example' => 'Tela de 6,5 polegadas, 128 GB, câmera dupla.',
            ],
            'weight_grams' => [
                'description' => 'Peso em gramas, usado no frete.',
                'example' => 350,
            ],
            'length_cm' => [
                'description' => 'Comprimento da embalagem em cm.',
                'example' => 20,
            ],
            'width_cm' => [
                'description' => 'Largura da embalagem em cm.',
                'example' => 12,
            ],
            'height_cm' => [
                'description' => 'Altura da embalagem em cm.',
                'example' => 8,
            ],
            'attributes' => [
                'description' => 'Valores dos atributos da categoria, por código: texto, número, booleano ou uma das opções. null remove o valor; códigos omitidos não mudam. Obrigatórios são cobrados no envio para moderação.',
                'example' => ['color' => 'preto', 'storage' => '128GB'],
            ],
            'attributes.*' => [
                'description' => 'Valor de um atributo (chave = código).',
                'example' => 'preto',
            ],
        ];
    }
}
