<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        $product = $this->route('product');

        return $this->user()?->can('update', $product) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'category_id' => [
                'sometimes',
                'required',
                'uuid',
                Rule::exists('categories', 'id')->where(fn ($query) => $query
                    ->where('is_active', true)
                    ->whereNull('deleted_at')),
            ],
            'name' => ['sometimes', 'required', 'string', 'min:3', 'max:180'],
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
        return (new StoreProductRequest)->bodyParameters();
    }
}
