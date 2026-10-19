<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRefundRequest;
use App\Http\Resources\RefundResource;
use App\Models\OrderSeller;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;

final class RefundController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService,
    ) {}

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
