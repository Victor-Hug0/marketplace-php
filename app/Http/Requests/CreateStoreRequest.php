<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Store;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Store::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'store_name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'commercial_email' => ['nullable', 'email', 'max:255'],
            'commercial_phone' => ['nullable', 'string', 'regex:/^\d{10,15}$/'],
            'origin_cep' => ['nullable', 'string', 'regex:/^\d{5}-?\d{3}$/'],
            'document_type' => ['required', Rule::in(['cpf', 'cnpj'])],
            'document' => [
                'required',
                'string',
                Rule::unique('stores', 'document'),
                function (string $attribute, mixed $value, Closure $fail): void {
                    $expectedLength = $this->string('document_type')->value() === 'cnpj' ? 14 : 11;

                    if (! is_string($value) || ! ctype_digit($value) || strlen($value) !== $expectedLength) {
                        $fail("O campo documento deve conter {$expectedLength} dígitos.");
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, array{description: string, example?: mixed}>
     */
    public function bodyParameters(): array
    {
        return [
            'store_name' => [
                'description' => 'Nome público da loja.',
                'example' => 'Loja Exemplo',
            ],
            'description' => [
                'description' => 'Apresentação da loja.',
                'example' => 'Eletrônicos com garantia e envio rápido.',
            ],
            'commercial_email' => [
                'description' => 'E-mail comercial.',
                'example' => 'contato@lojaexemplo.test',
            ],
            'commercial_phone' => [
                'description' => 'Telefone comercial, 10 a 15 dígitos.',
                'example' => '1133334444',
            ],
            'origin_cep' => [
                'description' => 'CEP de onde os pedidos saem, usado no frete.',
                'example' => '01310-100',
            ],
            'document_type' => [
                'description' => 'Tipo de documento do titular.',
                'example' => 'cnpj',
            ],
            'document' => [
                'description' => 'CPF (11) ou CNPJ (14) só com dígitos; único entre as lojas.',
                'example' => '11222333000181',
            ],
        ];
    }
}
