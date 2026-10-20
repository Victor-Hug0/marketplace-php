<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreReturnRequestRequest extends FormRequest
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
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'amount_cents' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, array{description: string, example?: mixed}>
     */
    public function bodyParameters(): array
    {
        return [
            'reason' => [
                'description' => 'Motivo da devolução (mínimo 10 caracteres).',
                'example' => 'Não serviu; produto lacrado.',
            ],
            'amount_cents' => [
                'description' => 'Valor pedido em centavos; padrão: o saldo ainda reembolsável do subpedido.',
                'example' => 189900,
            ],
        ];
    }
}
