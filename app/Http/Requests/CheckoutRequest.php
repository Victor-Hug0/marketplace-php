<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesIdempotencyKey;
use Illuminate\Foundation\Http\FormRequest;

final class CheckoutRequest extends FormRequest
{
    use ValidatesIdempotencyKey;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'address_id' => ['required', 'uuid', 'exists:addresses,id'],
        ];
    }
}
