<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ShippingQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'address_id' => ['required_without:cep', 'nullable', 'uuid'],
            'cep' => ['required_without:address_id', 'nullable', 'string', 'regex:/^\d{5}-?\d{3}$/'],
        ];
    }

    /**
     * @return array<string, array{description: string, example?: mixed}>
     */
    public function bodyParameters(): array
    {
        return [
            'address_id' => [
                'description' => 'Endereço salvo do comprador; obrigatório sem cep.',
                'example' => '01a159cb-1b3e-73da-b5a5-f225c2d77ad9',
            ],
            'cep' => [
                'description' => 'CEP de destino; obrigatório sem address_id.',
                'example' => '01311-000',
            ],
        ];
    }
}
