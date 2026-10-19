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
}
