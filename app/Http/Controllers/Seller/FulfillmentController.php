<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderSellerResource;
use App\Models\OrderSeller;
use App\Services\FulfillmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes as Doc;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Doc\Group('Envio')]
final class FulfillmentController extends Controller
{
    public function __construct(
        private readonly FulfillmentService $fulfillmentService,
    ) {}

    #[Doc\Subgroup('Seller')]
    #[Doc\Endpoint('Iniciar separação', '`paid → processing`.')]
    #[Doc\ResponseFromApiResource(OrderSellerResource::class, OrderSeller::class)]
    public function startProcessing(Request $request, OrderSeller $orderSeller): OrderSellerResource
    {
        $this->ensureSellerOwns($request, $orderSeller);
        $this->authorize('fulfill', $orderSeller);

        return new OrderSellerResource(
            $this->fulfillmentService->startProcessing($request->user(), $orderSeller),
        );
    }

    #[Doc\Subgroup('Seller')]
    #[Doc\Endpoint('Registrar envio', 'Gera o código de rastreio `SM#########BR` e a previsão de entrega pelo prazo cotado. A partir de `paid`, registra `processing` antes.')]
    #[Doc\ResponseFromApiResource(OrderSellerResource::class, OrderSeller::class, status: 201, with: ['shipment'])]
    public function ship(Request $request, OrderSeller $orderSeller): JsonResponse
    {
        $this->ensureSellerOwns($request, $orderSeller);
        $this->authorize('fulfill', $orderSeller);

        return (new OrderSellerResource(
            $this->fulfillmentService->ship($request->user(), $orderSeller),
        ))
            ->additional(['message' => 'Envio registrado com sucesso.'])
            ->response()
            ->setStatusCode(201);
    }

    #[Doc\Subgroup('Seller')]
    #[Doc\Endpoint('Confirmar entrega', '`shipped → delivered`.')]
    #[Doc\ResponseFromApiResource(OrderSellerResource::class, OrderSeller::class, with: ['shipment'])]
    public function markDelivered(Request $request, OrderSeller $orderSeller): OrderSellerResource
    {
        $this->ensureSellerOwns($request, $orderSeller);
        $this->authorize('markDelivered', $orderSeller);

        return new OrderSellerResource(
            $this->fulfillmentService->markDelivered($request->user(), $orderSeller),
        );
    }

    private function ensureSellerOwns(Request $request, OrderSeller $orderSeller): void
    {
        $orderSeller->loadMissing('store');

        if ($orderSeller->store?->user_id !== $request->user()?->id) {
            throw new NotFoundHttpException('Recurso não encontrado.');
        }
    }
}
