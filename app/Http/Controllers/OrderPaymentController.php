<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StorePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Order;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Knuckles\Scribe\Attributes as Doc;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Doc\Group('Pagamentos')]
final class OrderPaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService,
    ) {}

    #[Doc\Endpoint('Pagamentos do pedido')]
    #[Doc\ResponseFromApiResource(PaymentResource::class, Payment::class, collection: true)]
    public function index(Request $request, Order $order): AnonymousResourceCollection
    {
        $this->ensureBuyerOwnsOrder($request, $order);
        $this->authorize('view', $order);

        return PaymentResource::collection(
            $this->paymentService->orderPayments($order),
        );
    }

    #[Doc\Endpoint('Gerar pagamento', 'Pix, cartão ou boleto simulados. Cancela a tentativa pendente anterior e pode estender o prazo de pagamento do pedido. Mesma `Idempotency-Key` devolve o pagamento existente com 200.')]
    #[Doc\Header('Idempotency-Key', 'f3c1b2a4-0001')]
    #[Doc\ResponseFromApiResource(PaymentResource::class, Payment::class, status: 201)]
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
