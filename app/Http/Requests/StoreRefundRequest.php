<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesIdempotencyKey;
use Illuminate\Foundation\Http\FormRequest;

final class StoreRefundRequest extends FormRequest
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
            'amount_cents' => ['nullable', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    public function amountCents(): ?int
    {
        $amount = $this->validated('amount_cents');

        return $amount === null ? null : (int) $amount;
    }
}
