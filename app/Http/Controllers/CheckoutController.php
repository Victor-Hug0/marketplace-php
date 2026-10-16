<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CheckoutRequest;
use App\Http\Resources\OrderResource;
use App\Services\CheckoutService;
use Illuminate\Http\JsonResponse;

final class CheckoutController extends Controller
{
    public function __construct(
        private readonly CheckoutService $checkoutService,
    ) {}

    public function store(CheckoutRequest $request): JsonResponse
    {
        $result = $this->checkoutService->checkout(
            $request->user(),
            $request->string('address_id')->value(),
            $request->idempotencyKey(),
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
