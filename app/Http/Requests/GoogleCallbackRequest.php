<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class GoogleCallbackRequest extends FormRequest
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
            'code' => ['required_without:error', 'string', 'max:2048'],
            'error' => ['sometimes', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, array{description: string, example?: mixed}>
     */
    public function queryParameters(): array
    {
        return [
            'code' => [
                'description' => 'Código de autorização que o Google anexa ao redirecionar para esta URL.',
                'example' => '4/0AVMBsJh-exemplo',
            ],
            'error' => [
                'description' => 'Enviado pelo Google quando o usuário cancela ou nega o acesso.',
                'example' => 'access_denied',
            ],
        ];
    }
}
