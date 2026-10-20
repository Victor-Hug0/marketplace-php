<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'offer_id' => ['required', 'uuid', 'exists:offers,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:999'],
        ];
    }

    /**
     * @return array<string, array{description: string, example?: mixed}>
     */
    public function bodyParameters(): array
    {
        return [
            'offer_id' => [
                'description' => 'Oferta (loja + produto) a adicionar.',
                'example' => '01a159cb-2c4f-7a11-9d3e-4b5f6a7b8c9d',
            ],
            'quantity' => [
                'description' => 'Quantidade; somada se a oferta já estiver no carrinho.',
                'example' => 2,
            ],
        ];
    }
}
