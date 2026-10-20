<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\MockPaymentWebhookRequest;
use App\Http\Resources\PaymentWebhookEventResource;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes as Doc;

#[Doc\Group('Pagamentos')]
final class PaymentWebhookController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService,
    ) {}

    #[Doc\Endpoint('Webhook do gateway simulado', 'Exige o header `X-Mock-Webhook-Secret` igual a `PAYMENTS_MOCK_WEBHOOK_SECRET`. O evento é enfileirado (202); um `event_id` repetido não tem efeito (200).')]
    #[Doc\Unauthenticated]
    #[Doc\Header('X-Mock-Webhook-Secret', 'segredo-local')]
    #[Doc\Response('{"message":"Evento recebido.","data":{"event_id":"evt_0001","type":"payment.paid","processed_at":null}}', status: 202)]
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
