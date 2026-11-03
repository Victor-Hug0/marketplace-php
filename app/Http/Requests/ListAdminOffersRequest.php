<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\OfferStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListAdminOffersRequest extends FormRequest
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
            'status' => ['sometimes', 'string', Rule::enum(OfferStatus::class)],
            'store_id' => ['sometimes', 'uuid'],
            'product_id' => ['sometimes', 'uuid'],
            'admin_paused' => ['sometimes', 'boolean'],
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
            'status' => [
                'description' => 'Filtra por status da oferta (`active` ou `paused`).',
                'example' => 'paused',
            ],
            'store_id' => [
                'description' => 'Só ofertas desta loja.',
            ],
            'product_id' => [
                'description' => 'Só ofertas deste produto.',
            ],
            'admin_paused' => [
                'description' => 'Só as travadas pela moderação (1) ou só as livres (0).',
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
