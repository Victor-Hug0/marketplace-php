<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ResolveDisputeRequest extends FormRequest
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
            'outcome' => ['required', 'string', 'in:refund,reject'],
            'amount_cents' => ['sometimes', 'nullable', 'integer', 'min:1', 'prohibited_unless:outcome,refund'],
            'notes' => ['required', 'string', 'min:5', 'max:2000'],
        ];
    }

    public function refundsBuyer(): bool
    {
        return $this->validated('outcome') === 'refund';
    }

    /**
     * @return array<string, array{description: string, example?: mixed}>
     */
    public function bodyParameters(): array
    {
        return [
            'outcome' => [
                'description' => 'refund reembolsa o comprador; reject encerra sem reembolso.',
                'example' => 'refund',
            ],
            'amount_cents' => [
                'description' => 'Valor do reembolso em centavos (só com refund); padrão: o saldo ainda reembolsável do subpedido.',
                'example' => 189900,
            ],
            'notes' => [
                'description' => 'Justificativa registrada na disputa.',
                'example' => 'Vendedor confirmou o envio errado.',
            ],
        ];
    }
}
