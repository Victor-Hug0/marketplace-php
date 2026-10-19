<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\MockPaymentWebhookRequest;
use App\Http\Resources\PaymentWebhookEventResource;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;

final class PaymentWebhookController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService,
    ) {}

    public function mock(MockPaymentWebhookRequest $request): JsonResponse
    {
        $result = $this->paymentService->receiveMockWebhook(
            $request->string('event_id')->value(),
            $request->eventType(),
            $request->string('data.external_id')->value(),
            $request->validated(),
        );

        $duplicate = $result['duplicate'];

        return (new PaymentWebhookEventResource($result['event']))
            ->additional([
                'message' => $duplicate
                    ? 'Evento já recebido anteriormente.'
                    : 'Evento recebido.',
            ])
            ->response()
            ->setStatusCode($duplicate ? 200 : 202);
    }
}
