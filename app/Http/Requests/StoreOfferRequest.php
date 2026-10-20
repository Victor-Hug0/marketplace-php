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

    /**
     * @return array<string, array{description: string, example?: mixed}>
     */
    public function bodyParameters(): array
    {
        return [
            'product_id' => [
                'description' => 'Produto ativo do catálogo que a loja vai vender.',
                'example' => '01a159cb-3d5a-7b22-8e4f-5c6a7b8c9d0e',
            ],
            'price' => [
                'description' => 'Preço em reais, até 2 casas decimais.',
                'example' => 1899.0,
            ],
            'promotional_price' => [
                'description' => 'Preço promocional em reais; precisa ser menor que price.',
                'example' => 1799.9,
            ],
            'promo_starts_at' => [
                'description' => 'Início da promoção (ISO 8601).',
                'example' => '2026-11-01T00:00:00-03:00',
            ],
            'promo_ends_at' => [
                'description' => 'Fim da promoção (ISO 8601), depois do início.',
                'example' => '2026-11-30T23:59:59-03:00',
            ],
            'stock' => [
                'description' => 'Unidades disponíveis.',
                'example' => 25,
            ],
        ];
    }
}
