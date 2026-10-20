<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ReorderProductImagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $product = $this->route('product');

        return $this->user()?->can('manageImages', $product) ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'image_ids' => ['required', 'array', 'min:1'],
            'image_ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }

    /**
     * @return array<string, array{description: string, example?: mixed}>
     */
    public function bodyParameters(): array
    {
        return [
            'image_ids' => [
                'description' => 'Todos os IDs das imagens do produto, na nova ordem.',
                'example' => ['01a159cb-7b9e-7f66-c28d-9a0e1f2a3b4c', '01a159cb-6a8d-7e55-b17c-8f9d0e1f2a3b'],
            ],
            'image_ids.*' => [
                'description' => 'ID de uma imagem do produto.',
                'example' => '01a159cb-7b9e-7f66-c28d-9a0e1f2a3b4c',
            ],
        ];
    }
}
