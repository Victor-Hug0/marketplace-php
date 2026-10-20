<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        $address = $this->route('address');

        return $this->user()?->can('update', $address) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'nullable', 'string', 'max:60'],
            'recipient_name' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'phone' => ['sometimes', 'required', 'string', 'min:10', 'max:20'],
            'cep' => ['sometimes', 'required', 'string', 'regex:/^\d{5}-?\d{3}$/'],
            'street' => ['sometimes', 'required', 'string', 'min:2', 'max:180'],
            'number' => ['sometimes', 'required', 'string', 'max:20'],
            'complement' => ['sometimes', 'nullable', 'string', 'max:120'],
            'district' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'city' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'state' => ['sometimes', 'required', 'string', 'size:2', Rule::in($this->brazilianStates())],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return list<string>
     */
    private function brazilianStates(): array
    {
        return [
            'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA',
            'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN',
            'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO',
        ];
    }

    /**
     * @return array<string, array{description: string, example?: mixed}>
     */
    public function bodyParameters(): array
    {
        return (new StoreAddressRequest)->bodyParameters();
    }
}
