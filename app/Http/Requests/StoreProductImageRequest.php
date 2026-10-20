<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreProductImageRequest extends FormRequest
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
            'image' => ['required', 'file', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
            'alt' => ['sometimes', 'nullable', 'string', 'max:180'],
        ];
    }

    /**
     * @return array<string, array{description: string, example?: mixed}>
     */
    public function bodyParameters(): array
    {
        return [
            'image' => [
                'description' => 'Arquivo JPEG, PNG ou WebP de até 2 MB.',
            ],
            'alt' => [
                'description' => 'Texto alternativo.',
                'example' => 'Smartphone visto de frente',
            ],
        ];
    }
}
