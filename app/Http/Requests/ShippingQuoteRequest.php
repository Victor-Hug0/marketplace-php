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
}
