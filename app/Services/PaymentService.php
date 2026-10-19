<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentWebhookEventType;
use App\Enums\PaymentWebhookResult;
use App\Jobs\ProcessPaymentWebhookEvent;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PaymentService
{
    private const PROVIDER = 'mock';

    public function __construct(
        private readonly OrderService $orderService,
    ) {}

    /**
     * @return Collection<int, Payment>
     */
    public function orderPayments(Order $order): Collection
    {
        return Payment::query()
            ->where('order_id', $order->id)
            ->latest()
            ->get();
    }

    /**
     * @return array{payment: Payment, created: bool}
     */
    public function createIntent(
        User $buyer,
        Order $order,
        PaymentMethod $method,
        string $idempotencyKey,
    ): array {
        return DB::transaction(function () use ($buyer, $order, $method, $idempotencyKey): array {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->buyer_id !== $buyer->id) {
                throw new NotFoundHttpException('Recurso não encontrado.');
            }

            $replay = Payment::query()
                ->where('order_id', $order->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($replay !== null) {
                if ($replay->method !== $method) {
                    throw new ConflictHttpException(
                        'A chave de idempotência já foi usada com outro método de pagamento.',
                    );
                }

                return ['payment' => $replay, 'created' => false];
            }

            if ($order->status !== OrderStatus::PENDING_PAYMENT) {
                throw new ConflictHttpException(
                    'Somente pedidos em pending_payment podem receber pagamento.',
                );
            }

            $amountCents = $this->orderService->payableAmountCents($order);
            if ($amountCents < 1) {
                throw new ConflictHttpException('O pedido não possui valor a pagar.');
            }

            $superseded = Payment::query()
                ->where('order_id', $order->id)
                ->where('status', PaymentStatus::PENDING)
                ->lockForUpdate()
                ->get();

            foreach ($superseded as $previous) {
                $previous->update([
                    'status' => PaymentStatus::CANCELLED,
                    'cancelled_at' => now(),
                ]);
            }

            $externalId = 'mock_pay_'.Str::lower((string) Str::ulid());

            $payment = Payment::query()->create([
                'order_id' => $order->id,
                'provider' => self::PROVIDER,
                'method' => $method,
                'status' => PaymentStatus::PENDING,
                'external_id' => $externalId,
                'idempotency_key' => $idempotencyKey,
                'currency' => $order->currency,
                'amount_cents' => $amountCents,
                'payload' => $this->mockInstructions($method, $externalId),
            ]);

            $this->audit($buyer, $order, 'payment.intent_created', [
                'payment_id' => $payment->id,
                'method' => $method->value,
                'amount_cents' => $amountCents,
                'superseded_payments' => $superseded->count(),
            ]);

            return ['payment' => $payment, 'created' => true];
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{event: PaymentWebhookEvent, duplicate: bool}
     */
    public function receiveMockWebhook(
        string $eventId,
        PaymentWebhookEventType $type,
        string $externalId,
        array $payload,
    ): array {
        $payment = Payment::query()
            ->where('provider', self::PROVIDER)
            ->where('external_id', $externalId)
            ->first();

        if ($payment === null) {
            throw new NotFoundHttpException('Pagamento não encontrado.');
        }

        $event = PaymentWebhookEvent::query()->createOrFirst(
            ['provider' => self::PROVIDER, 'event_id' => $eventId],
            ['type' => $type, 'payment_id' => $payment->id, 'payload' => $payload],
        );

        $duplicate = ! $event->wasRecentlyCreated;

        if ($duplicate && ($event->payment_id !== $payment->id || $event->type !== $type)) {
            throw new ConflictHttpException('O event_id informado já foi usado em outro evento.');
        }

        if (! $event->isProcessed()) {
            ProcessPaymentWebhookEvent::dispatch($event->id);
        }

        return ['event' => $event->refresh(), 'duplicate' => $duplicate];
    }

    public function processWebhookEvent(string $webhookEventId): void
    {
        DB::transaction(function () use ($webhookEventId): void {
            $event = PaymentWebhookEvent::query()
                ->lockForUpdate()
                ->find($webhookEventId);

            if ($event === null || $event->isProcessed()) {
                return;
            }

            $orderId = Payment::query()->whereKey($event->payment_id)->value('order_id');
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);
            $payment = Payment::query()->lockForUpdate()->findOrFail($event->payment_id);

            $result = match ($event->type) {
                PaymentWebhookEventType::PAYMENT_PAID => $this->applyPaid($payment, $order),
                PaymentWebhookEventType::PAYMENT_FAILED => $this->applyFailed($payment),
            };

            $event->update([
                'processed_at' => now(),
                'result' => $result,
            ]);

            $this->audit(null, $order, 'payment.webhook_processed', [
                'payment_id' => $payment->id,
                'event_id' => $event->event_id,
                'type' => $event->type->value,
                'result' => $result->value,
            ]);
        });
    }

    private function applyPaid(Payment $payment, Order $order): PaymentWebhookResult
    {
        if ($payment->status === PaymentStatus::PAID) {
            return PaymentWebhookResult::ALREADY_PAID;
        }

        if ($payment->status !== PaymentStatus::PENDING) {
            return PaymentWebhookResult::PAYMENT_NOT_PENDING;
        }

        if ($order->status !== OrderStatus::PENDING_PAYMENT) {
            return PaymentWebhookResult::ORDER_NOT_PAYABLE;
        }

        $payment->update([
            'status' => PaymentStatus::PAID,
            'paid_at' => now(),
        ]);

        $this->orderService->markPaid(
            $order,
            "Pagamento confirmado via {$payment->method->value} (mock).",
        );

        return PaymentWebhookResult::APPLIED;
    }

    private function applyFailed(Payment $payment): PaymentWebhookResult
    {
        if ($payment->status !== PaymentStatus::PENDING) {
            return PaymentWebhookResult::PAYMENT_NOT_PENDING;
        }

        $payment->update([
            'status' => PaymentStatus::FAILED,
            'failed_at' => now(),
        ]);

        return PaymentWebhookResult::APPLIED;
    }

    /**
     * Fake gateway instructions. Never collects or stores card data.
     *
     * @return array<string, string>
     */
    private function mockInstructions(PaymentMethod $method, string $externalId): array
    {
        return match ($method) {
            PaymentMethod::PIX => [
                'qr_code_text' => '00020126580014br.gov.bcb.pix0136'.$externalId.'5204000053039865802BR6304MOCK',
                'expires_at' => now()
                    ->addMinutes((int) config('payments.mock.pix_ttl_minutes'))
                    ->toIso8601String(),
            ],
            PaymentMethod::BOLETO => [
                'digitable_line' => $this->randomDigits(47),
                'barcode' => $this->randomDigits(44),
                'due_date' => now()
                    ->addDays((int) config('payments.mock.boleto_due_days'))
                    ->toDateString(),
            ],
            PaymentMethod::CREDIT_CARD => [
                'checkout_url' => rtrim((string) config('payments.mock.checkout_base_url'), '/').'/'.$externalId,
            ],
        };
    }

    private function randomDigits(int $length): string
    {
        $digits = '';

        for ($i = 0; $i < $length; $i++) {
            $digits .= (string) random_int(0, 9);
        }

        return $digits;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function audit(?User $actor, Order $order, string $action, array $payload = []): void
    {
        AuditLog::query()->create([
            'action' => $action,
            'actor_id' => $actor?->id,
            'actor_type' => $actor?->getMorphClass(),
            'auditable_id' => $order->id,
            'auditable_type' => $order->getMorphClass(),
            'payload' => $payload,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
        ]);
    }
}
