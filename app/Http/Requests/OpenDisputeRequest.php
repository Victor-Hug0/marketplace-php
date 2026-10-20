<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\DisputeReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class OpenDisputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::enum(DisputeReason::class)],
            'description' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    /**
     * @return array<string, array{description: string, example?: mixed}>
     */
    public function bodyParameters(): array
    {
        return [
            'reason' => [
                'description' => 'Motivo da disputa.',
                'example' => 'not_as_described',
            ],
            'description' => [
                'description' => 'Relato do problema (mínimo 10 caracteres).',
                'example' => 'A cor recebida é diferente da anunciada.',
            ],
        ];
    }
}
