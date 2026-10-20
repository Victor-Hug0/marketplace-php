<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateCategoryRequest extends FormRequest
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
        return [
            'parent_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('categories', 'id')->whereNull('deleted_at')],
            'name' => ['sometimes', 'string', 'max:120'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255', 'alpha_dash'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'position' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, array{description: string, example?: mixed}>
     */
    public function bodyParameters(): array
    {
        return (new StoreCategoryRequest)->bodyParameters();
    }
}
