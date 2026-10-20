<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CheckoutRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\CheckoutService;
use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes as Doc;

#[Doc\Group('Checkout')]
final class CheckoutController extends Controller
{
    public function __construct(
        private readonly CheckoutService $checkoutService,
    ) {}

    #[Doc\Endpoint('Fechar pedido', 'Transação única: revalida ofertas e estoque, congela preços e frete, cria um subpedido por loja, reserva o estoque e esvazia o carrinho. Mesma `Idempotency-Key` devolve o pedido existente com 200.')]
    #[Doc\Header('Idempotency-Key', 'f3c1b2a4-0001')]
    #[Doc\ResponseFromApiResource(OrderResource::class, Order::class, status: 201, with: ['sellers'])]
    #[Doc\Response('{"message":"Estoque insuficiente para concluir o checkout.","timestamp":"2026-10-20T10:00:00-03:00","errors":[]}', status: 409, description: 'Oferta, loja ou produto inativo, estoque insuficiente ou frete indisponível')]
    public function store(CheckoutRequest $request): JsonResponse
    {
        $result = $this->checkoutService->checkout(
            $request->user(),
            $request->string('address_id')->value(),
            $request->idempotencyKey(),
            $request->validated('shipping_service_code'),
        );

        $order = $result['order'];
        $created = $result['created'];

        $this->authorize('view', $order);

        $status = $created ? 201 : 200;

        return (new OrderResource($order))
            ->additional([
                'message' => $created
                    ? 'Checkout realizado com sucesso.'
                    : 'Pedido já existente para esta chave de idempotência.',
            ])
            ->response()
            ->setStatusCode($status);
    }
}
