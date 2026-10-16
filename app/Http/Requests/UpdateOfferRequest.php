<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        $offer = $this->route('offer');

        return $this->user()?->can('update', $offer) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'price' => ['sometimes', 'required', 'numeric', 'gt:0', 'decimal:0,2'],
            'promotional_price' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'decimal:0,2'],
            'promo_starts_at' => ['sometimes', 'nullable', 'date'],
            'promo_ends_at' => ['sometimes', 'nullable', 'date', 'after:promo_starts_at'],
            'stock' => ['sometimes', 'required', 'integer', 'min:0'],
        ];
    }
}
