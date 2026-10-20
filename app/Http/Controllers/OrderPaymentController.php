<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StorePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Order;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class OrderPaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService,
    ) {}

    public function index(Request $request, Order $order): AnonymousResourceCollection
    {
        $this->ensureBuyerOwnsOrder($request, $order);
        $this->authorize('view', $order);

        return PaymentResource::collection(
            $this->paymentService->orderPayments($order),
        );
    }

    public function store(StorePaymentRequest $request, Order $order): JsonResponse
    {
        $this->ensureBuyerOwnsOrder($request, $order);
        $this->authorize('pay', $order);

        $result = $this->paymentService->createIntent(
            $request->user(),
            $order,
            $request->paymentMethod(),
            $request->idempotencyKey(),
        );

        $created = $result['created'];

        return (new PaymentResource($result['payment']))
            ->additional([
                'message' => $created
                    ? 'Pagamento iniciado com sucesso.'
                    : 'Pagamento já existente para esta chave de idempotência.',
            ])
            ->response()
            ->setStatusCode($created ? 201 : 200);
    }

    private function ensureBuyerOwnsOrder(Request $request, Order $order): void
    {
        $userId = $request->user()?->getAuthIdentifier();

        if ($order->buyer_id !== $userId) {
            throw new NotFoundHttpException('Recurso não encontrado.');
        }
    }
}
