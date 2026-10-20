<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\PaymentWebhookEventType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class MockPaymentWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'event_id' => ['required', 'string', 'max:100'],
            'type' => ['required', 'string', Rule::enum(PaymentWebhookEventType::class)],
            'data' => ['required', 'array'],
            'data.external_id' => ['required', 'string', 'max:100'],
        ];
    }

    public function eventType(): PaymentWebhookEventType
    {
        return PaymentWebhookEventType::from($this->string('type')->value());
    }

    /**
     * @return array<string, array{description: string, example?: mixed}>
     */
    public function bodyParameters(): array
    {
        return [
            'event_id' => [
                'description' => 'ID único do evento no gateway; repetido = ignorado (idempotente).',
                'example' => 'evt_000123',
            ],
            'type' => [
                'description' => 'Tipo do evento.',
                'example' => 'payment.paid',
            ],
            'data' => [
                'description' => 'Dados do evento.',
            ],
            'data.external_id' => [
                'description' => 'external_id do pagamento, devolvido ao criar a intenção.',
                'example' => 'mock_pay_01k7z3m9q4w2x8y6v5t3r1n0bc',
            ],
        ];
    }
}
