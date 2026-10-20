<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DisputeReason;
use App\Http\Requests\OpenDisputeRequest;
use App\Http\Requests\StoreReturnRequestRequest;
use App\Http\Resources\DisputeResource;
use App\Http\Resources\ReturnRequestResource;
use App\Models\OrderSeller;
use App\Services\DisputeService;
use App\Services\ReturnRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes as Doc;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Doc\Group('Pós-venda')]
final class PostSaleController extends Controller
{
    public function __construct(
        private readonly DisputeService $disputeService,
        private readonly ReturnRequestService $returnRequestService,
    ) {}

    #[Doc\Subgroup('Comprador')]
    #[Doc\Endpoint('Abrir disputa', 'Subpedido `delivered`, até 7 dias após a entrega, uma por subpedido. Enquanto aberta, o saldo do subpedido fica travado para saque.')]
    #[Doc\Response('{"data":{"id":"01a1462e-6a1c-73a8-9a51-0e8c2d7f4b10","order_seller_id":"01a1461f-2b3c-7d4e-8f90-1a2b3c4d5e6f","status":"open","reason":"not_as_described","description":"A cor do produto é diferente da anunciada.","seller_response":null,"resolution_notes":null,"refund_id":null},"message":"Disputa aberta com sucesso."}', status: 201)]
    public function openDispute(OpenDisputeRequest $request, OrderSeller $orderSeller): JsonResponse
    {
        $this->ensureBuyerOwns($request, $orderSeller);
        $this->authorize('openPostSale', $orderSeller);

        $dispute = $this->disputeService->open(
            $request->user(),
            $orderSeller,
            DisputeReason::from($request->validated('reason')),
            $request->validated('description'),
        );

        return (new DisputeResource($dispute))
            ->additional(['message' => 'Disputa aberta com sucesso.'])
            ->response()
            ->setStatusCode(201);
    }

    #[Doc\Subgroup('Comprador')]
    #[Doc\Endpoint('Pedir devolução', 'Mesmas regras de prazo; sem `amount_cents`, pede todo o restante reembolsável. O saldo fica travado até a decisão.')]
    #[Doc\Response('{"data":{"id":"01a14630-1d2e-7f40-8a5b-6c7d8e9f0a1b","order_seller_id":"01a1461f-2b3c-7d4e-8f90-1a2b3c4d5e6f","status":"requested","reason":"Não serviu, quero devolver o produto.","amount_cents":9990,"refund_id":null},"message":"Devolução solicitada com sucesso."}', status: 201)]
    public function requestReturn(StoreReturnRequestRequest $request, OrderSeller $orderSeller): JsonResponse
    {
        $this->ensureBuyerOwns($request, $orderSeller);
        $this->authorize('openPostSale', $orderSeller);

        $returnRequest = $this->returnRequestService->request(
            $request->user(),
            $orderSeller,
            $request->validated('reason'),
            $request->validated('amount_cents'),
        );

        return (new ReturnRequestResource($returnRequest))
            ->additional(['message' => 'Devolução solicitada com sucesso.'])
            ->response()
            ->setStatusCode(201);
    }

    private function ensureBuyerOwns(Request $request, OrderSeller $orderSeller): void
    {
        $orderSeller->loadMissing('order:id,buyer_id');

        if ($orderSeller->order?->buyer_id !== $request->user()?->id) {
            throw new NotFoundHttpException('Recurso não encontrado.');
        }
    }
}
