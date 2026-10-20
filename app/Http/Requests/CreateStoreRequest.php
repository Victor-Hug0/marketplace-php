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
}
