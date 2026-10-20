<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Address;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Address::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'nullable', 'string', 'max:60'],
            'recipient_name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'min:10', 'max:20'],
            'cep' => ['required', 'string', 'regex:/^\d{5}-?\d{3}$/'],
            'street' => ['required', 'string', 'min:2', 'max:180'],
            'number' => ['required', 'string', 'max:20'],
            'complement' => ['sometimes', 'nullable', 'string', 'max:120'],
            'district' => ['required', 'string', 'min:2', 'max:120'],
            'city' => ['required', 'string', 'min:2', 'max:120'],
            'state' => ['required', 'string', 'size:2', Rule::in($this->brazilianStates())],
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
        return [
            'label' => [
                'description' => 'Apelido do endereço.',
                'example' => 'Casa',
            ],
            'recipient_name' => [
                'description' => 'Nome de quem recebe a entrega.',
                'example' => 'Maria Souza',
            ],
            'phone' => [
                'description' => 'Telefone de contato com DDD, só dígitos.',
                'example' => '11987654321',
            ],
            'cep' => [
                'description' => 'CEP de destino, com ou sem hífen.',
                'example' => '01311-000',
            ],
            'street' => [
                'description' => 'Logradouro.',
                'example' => 'Avenida Paulista',
            ],
            'number' => [
                'description' => 'Número.',
                'example' => '1000',
            ],
            'complement' => [
                'description' => 'Complemento.',
                'example' => 'Apto 42',
            ],
            'district' => [
                'description' => 'Bairro.',
                'example' => 'Bela Vista',
            ],
            'city' => [
                'description' => 'Cidade.',
                'example' => 'São Paulo',
            ],
            'state' => [
                'description' => 'UF com 2 letras.',
                'example' => 'SP',
            ],
            'is_default' => [
                'description' => 'Torna este o endereço padrão (o anterior deixa de ser).',
                'example' => true,
            ],
        ];
    }
}
