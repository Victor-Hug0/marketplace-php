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

    /**
     * @return array<string, array{description: string, example?: mixed}>
     */
    public function bodyParameters(): array
    {
        return [
            'rating' => [
                'description' => 'Nota de 1 a 5.',
                'example' => 5,
            ],
            'title' => [
                'description' => 'Título da avaliação.',
                'example' => 'Excelente',
            ],
            'body' => [
                'description' => 'Comentário.',
                'example' => 'Chegou antes do prazo e funciona muito bem.',
            ],
        ];
    }
}
