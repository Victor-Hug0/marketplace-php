<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Validator;

trait ValidatesIdempotencyKey
{
    public function idempotencyKey(): string
    {
        $header = $this->header('Idempotency-Key');

        return is_string($header) ? trim($header) : '';
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
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
