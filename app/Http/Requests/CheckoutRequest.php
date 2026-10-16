<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CheckoutRequest extends FormRequest
{
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

    public function idempotencyKey(): string
    {
        $header = $this->header('Idempotency-Key');

        return is_string($header) ? trim($header) : '';
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $key = $this->idempotencyKey();

            if ($key === '') {
                $validator->errors()->add(
                    'Idempotency-Key',
                    'O header Idempotency-Key é obrigatório.',
                );

                return;
            }

            if (strlen($key) < 8 || strlen($key) > 128) {
                $validator->errors()->add(
                    'Idempotency-Key',
                    'O header Idempotency-Key deve ter entre 8 e 128 caracteres.',
                );
            }
        });
    }
}
