<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesIdempotencyKey;
use Illuminate\Foundation\Http\FormRequest;

final class CheckoutRequest extends FormRequest
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
            'address_id' => ['required', 'uuid', 'exists:addresses,id'],
            'shipping_service_code' => ['sometimes', 'string', 'max:60'],
        ];
    }

    /**
     * @return array<string, array{description: string, example?: mixed}>
     */
    public function bodyParameters(): array
    {
        return [
            'address_id' => [
                'description' => 'Endereço de entrega do comprador.',
                'example' => '01a159cb-1b3e-73da-b5a5-f225c2d77ad9',
            ],
            'shipping_service_code' => [
                'description' => 'service_code de uma opção da cotação de frete; padrão: simulated_standard.',
                'example' => 'simulated_express',
            ],
        ];
    }
}
