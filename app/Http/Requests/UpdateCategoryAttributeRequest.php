<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\CategoryAttributeDataType;
use App\Models\Category;
use App\Models\CategoryAttribute;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateCategoryAttributeRequest extends FormRequest
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
        /** @var Category|null $category */
        $category = $this->route('category');
        /** @var CategoryAttribute|null $attribute */
        $attribute = $this->route('attribute');

        $dataType = $this->input('data_type', $attribute?->data_type->value);

        return [
            'code' => [
                'sometimes',
                'string',
                'max:64',
                'alpha_dash',
                Rule::unique('category_attributes', 'code')
                    ->where(fn ($query) => $query->where('category_id', $category?->id))
                    ->ignore($attribute?->id),
            ],
            'label' => ['sometimes', 'string', 'max:120'],
            'data_type' => ['sometimes', Rule::enum(CategoryAttributeDataType::class)],
            'options' => [
                Rule::requiredIf(
                    fn (): bool => $dataType === CategoryAttributeDataType::ENUM->value,
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
