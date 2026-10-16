<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ProductStatus;
use App\Models\Offer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Offer::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'product_id' => [
                'required',
                'uuid',
                Rule::exists('products', 'id')->where(fn ($query) => $query
                    ->where('status', ProductStatus::ACTIVE->value)
                    ->whereNull('deleted_at')),
            ],
            'price' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'promotional_price' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'decimal:0,2', 'lt:price'],
            'promo_starts_at' => ['sometimes', 'nullable', 'date'],
            'promo_ends_at' => ['sometimes', 'nullable', 'date', 'after:promo_starts_at'],
            'stock' => ['required', 'integer', 'min:0'],
        ];
    }
}
