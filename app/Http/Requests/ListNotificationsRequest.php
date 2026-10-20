<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ListNotificationsRequest extends FormRequest
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
            'unread' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'nullable', 'string'],
        ];
    }

    /**
     * @return array<string, array{description: string, example?: mixed}>
     */
    public function queryParameters(): array
    {
        return [
            'unread' => [
                'description' => 'Só as não lidas (1) ou todas (0, padrão).',
                'example' => 1,
            ],
            'per_page' => [
                'description' => 'Itens por página (1 a 100).',
                'example' => 20,
            ],
            'cursor' => [
                'description' => 'Cursor devolvido em meta.next_cursor.',
            ],
        ];
    }
}
