<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'cpf' => 'required|string|max:11|unique:users',
            'phone_number' => 'required|string|max:11',
            'password' => ['required', 'confirmed', Password::defaults()],
            'password_confirmation' => ['required', Password::defaults()],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'O nome é obrigatório',
            'email.required' => 'O email é obrigatório',
            'email.email' => 'O email deve ser um email válido',
            'email.unique' => 'O email já está em uso',
            'cpf.required' => 'O CPF é obrigatório',
            'cpf.string' => 'O CPF deve ser uma string',
            'cpf.max' => 'O CPF deve ter no máximo 11 caracteres',
            'cpf.unique' => 'O CPF já está em uso',
            'phone_number.required' => 'O número de telefone é obrigatório',
            'phone_number.string' => 'O número de telefone deve ser uma string',
            'phone_number.max' => 'O número de telefone deve ter no máximo 11 caracteres',
            'password.letters' => 'A senha deve conter pelo menos uma letra.',
            'password.mixed' => 'A senha deve conter letras maiúsculas e minúsculas.',
            'password.numbers' => 'A senha deve conter pelo menos um número.',
            'password.symbols' => 'A senha deve conter pelo menos um símbolo.',
            'password.uncompromised' => 'Esta senha apareceu em um vazamento de dados. Escolha outra.',
        ];
    }

    /**
     * @return array<string, array{description: string, example?: mixed}>
     */
    public function bodyParameters(): array
    {
        return [
            'name' => [
                'description' => 'Nome completo.',
                'example' => 'Maria Souza',
            ],
            'email' => [
                'description' => 'E-mail único.',
                'example' => 'maria@example.com',
            ],
            'cpf' => [
                'description' => 'CPF só com dígitos; único.',
                'example' => '52998224725',
            ],
            'phone_number' => [
                'description' => 'Celular com DDD, só dígitos.',
                'example' => '11987654321',
            ],
            'password' => [
                'description' => '8 a 64 caracteres com maiúscula, minúscula, número e símbolo.',
                'example' => 'Senha@Forte123',
            ],
            'password_confirmation' => [
                'description' => 'Repita a senha.',
                'example' => 'Senha@Forte123',
            ],
        ];
    }
}
