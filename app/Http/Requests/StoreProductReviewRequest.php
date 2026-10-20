<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ProductReview;
use Illuminate\Foundation\Http\FormRequest;

final class StoreProductReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', [ProductReview::class, $this->route('orderItem')]) ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'between:1,5'],
            'title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'body' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
