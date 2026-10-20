<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRefundRequest;
use App\Http\Resources\RefundResource;
use App\Models\OrderSeller;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes as Doc;

#[Doc\Group('Financeiro')]
final class RefundController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService,
    ) {}

    #[Doc\Subgroup('Admin')]
    #[Doc\Endpoint('Reembolsar subpedido', 'Sem `amount_cents`, reembolsa todo o restante. Estorna o ledger proporcionalmente e repõe o estoque só no reembolso total antes do envio.')]
    #[Doc\Header('Idempotency-Key', 'f3c1b2a4-0001')]
    #[Doc\Response('{"data":{"id":"01a14650-4b5c-7d6e-8f90-a1b2c3d4e5f6","order_seller_id":"01a1461f-2b3c-7d4e-8f90-1a2b3c4d5e6f","payment_id":"01a14520-7c8d-7e9f-a0b1-c2d3e4f5a6b7","external_id":"mock_ref_01k8x9y2z3","amount_cents":9990,"currency":"BRL","reason":"Produto divergente do anúncio.","restocked":false,"processed_at":"2026-10-20T10:00:00-03:00","created_at":"2026-10-20T10:00:00-03:00"},"message":"Reembolso realizado com sucesso."}', status: 201)]
    public function store(StoreRefundRequest $request, OrderSeller $orderSeller): JsonResponse
    {
        $this->authorize('refund', $orderSeller);

        $result = $this->orderService->refundByAdmin(
            $request->user(),
            $orderSeller,
            $request->amountCents(),
            $request->string('reason')->value(),
            $request->idempotencyKey(),
        );

        $created = $result['created'];

        return (new RefundResource($result['refund']))
            ->additional([
                'message' => $created
                    ? 'Reembolso realizado com sucesso.'
                    : 'Reembolso já existente para esta chave de idempotência.',
            ])
            ->response()
            ->setStatusCode($created ? 201 : 200);
    }
}
