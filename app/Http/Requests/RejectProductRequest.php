<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RejectProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        $product = $this->route('product');

        return $this->user()?->can('review', $product) ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    /**
     * @return array<string, array{description: string, example?: mixed}>
     */
    public function bodyParameters(): array
    {
        return [
            'reason' => [
                'description' => 'Motivo da recusa, enviado ao vendedor.',
                'example' => 'As fotos não mostram o produto anunciado.',
            ],
        ];
    }
}
