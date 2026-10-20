<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesIdempotencyKey;
use Illuminate\Foundation\Http\FormRequest;

final class StorePayoutRequest extends FormRequest
{
    use ValidatesIdempotencyKey;

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
            'amount_cents' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, array{description: string, example?: mixed}>
     */
    public function bodyParameters(): array
    {
        return [
            'amount_cents' => [
                'description' => 'Valor do saque em centavos; até o saldo disponível.',
                'example' => 50000,
            ],
        ];
    }
}
