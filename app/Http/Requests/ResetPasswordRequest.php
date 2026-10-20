<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * @return array<string, array{description: string, example?: mixed}>
     */
    public function bodyParameters(): array
    {
        return [
            'token' => [
                'description' => 'Token recebido no link do e-mail (parâmetro token).',
                'example' => '3f6c1d0e9b8a7f6e5d4c3b2a1f0e9d8c7b6a5f4e3d2c1b0a9f8e7d6c5b4a3f2e',
            ],
            'email' => [
                'description' => 'E-mail da conta (parâmetro email do link).',
                'example' => 'comprador@marketplace.test',
            ],
            'password' => [
                'description' => 'Nova senha: 8 a 64 caracteres com maiúscula, minúscula, número e símbolo.',
                'example' => 'NovaSenha@123',
            ],
            'password_confirmation' => [
                'description' => 'Repita a nova senha.',
                'example' => 'NovaSenha@123',
            ],
        ];
    }
}
