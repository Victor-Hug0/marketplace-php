<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\CategoryAttributeDataType;
use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreCategoryAttributeRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Category $category */
        $category = $this->route('category');

        return $this->user()?->can('manage', $category) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Category $category */
        $category = $this->route('category');

        return [
            'code' => [
                'required',
                'string',
                'max:64',
                'alpha_dash',
                Rule::unique('category_attributes', 'code')->where(
                    fn ($query) => $query->where('category_id', $category->id),
                ),
            ],
            'label' => ['required', 'string', 'max:120'],
            'data_type' => ['required', Rule::enum(CategoryAttributeDataType::class)],
            'options' => [
                Rule::requiredIf(
                    fn (): bool => $this->string('data_type')->value() === CategoryAttributeDataType::ENUM->value,
                ),
                'nullable',
                'array',
                'min:1',
            ],
            'options.*' => ['required', 'string', 'max:120'],
            'is_required' => ['sometimes', 'boolean'],
            'is_filterable' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
