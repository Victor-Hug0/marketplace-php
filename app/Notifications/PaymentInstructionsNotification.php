<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\PaymentMethod;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Support\Carbon;

final class PaymentInstructionsNotification extends TransactionalNotification
{
    public function __construct(public readonly Payment $payment)
    {
        parent::__construct();
    }

    public function type(): string
    {
        return 'payment.instructions';
    }

    protected function title(): string
    {
        return match ($this->payment->method) {
            PaymentMethod::PIX => 'Seu Pix para pagamento',
            PaymentMethod::BOLETO => 'Seu boleto para pagamento',
            PaymentMethod::CREDIT_CARD => 'Finalize o pagamento com cartão',
        };
    }

    protected function lines(): array
    {
        $payload = $this->payment->payload ?? [];
        $amount = 'Valor: '.Money::formatBrl($this->payment->amount_cents).'.';

        return match ($this->payment->method) {
            PaymentMethod::PIX => [
                $amount,
                'Código Pix copia e cola: '.($payload['qr_code_text'] ?? '-'),
                'Válido até '.$this->dateTime(isset($payload['expires_at']) ? Carbon::parse($payload['expires_at']) : null).'.',
            ],
            PaymentMethod::BOLETO => [
                $amount,
                'Linha digitável: '.($payload['digitable_line'] ?? '-'),
                'Vencimento: '.(isset($payload['due_date']) ? Carbon::parse($payload['due_date'])->format('d/m/Y') : '-').'.',
            ],
            PaymentMethod::CREDIT_CARD => [
                $amount,
                'Use o botão abaixo para informar o cartão no ambiente do gateway; a loja nunca recebe os dados do cartão.',
            ],
        };
    }

    protected function actionPath(): string
    {
        return "/pedidos/{$this->payment->order_id}";
    }

    protected function actionText(): string
    {
        return 'Ver pedido';
    }

    protected function data(): array
    {
        return [
            'payment_id' => $this->payment->id,
            'order_id' => $this->payment->order_id,
            'method' => $this->payment->method->value,
            'amount_cents' => $this->payment->amount_cents,
        ];
    }
}
