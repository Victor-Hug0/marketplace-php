<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RespondDisputeRequest extends FormRequest
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
            'response' => ['required', 'string', 'min:5', 'max:2000'],
        ];
    }

    /**
     * @return array<string, array{description: string, example?: mixed}>
     */
    public function bodyParameters(): array
    {
        return [
            'response' => [
                'description' => 'Resposta do vendedor à disputa.',
                'example' => 'Enviamos outra unidade; código de rastreio em anexo.',
            ],
        ];
    }
}
