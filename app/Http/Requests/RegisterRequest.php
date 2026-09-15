<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

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
            'password' => 'required|string|min:8|max:64|confirmed',
            'password_confirmation' => 'required|string|min:8|max:64',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'O nome é obrigatório',
            'email.required' => 'O email é obrigatório',
            'email.email' => 'O email deve ser um email válido',
            'email.unique' => 'O email já está em uso',
            'password.required' => 'A senha é obrigatória',
            'password.string' => 'A senha deve ser uma string',
            'password.min' => 'A senha deve ter pelo menos 8 caracteres',
            'password.max' => 'A senha deve ter no máximo 64 caracteres',
            'password.confirmed' => 'A senha e a confirmação de senha não conferem',
            'password_confirmation.required' => 'A confirmação de senha é obrigatória',
            'password_confirmation.string' => 'A confirmação de senha deve ser uma string',
            'password_confirmation.min' => 'A confirmação de senha deve ter pelo menos 8 caracteres',
            'password_confirmation.max' => 'A confirmação de senha deve ter no máximo 64 caracteres',
        ];
    }
}
