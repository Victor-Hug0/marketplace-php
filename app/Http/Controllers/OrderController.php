<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CancelOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use App\Support\Pagination\CursorPaginates;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Knuckles\Scribe\Attributes as Doc;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Doc\Group('Pedidos')]
final class OrderController extends Controller
{
    use CursorPaginates;

    public function __construct(
        private readonly OrderService $orderService,
    ) {}

    #[Doc\Subgroup('Comprador')]
    #[Doc\Endpoint('Meus pedidos', 'Mais recentes primeiro.')]
    #[Doc\ResponseFromApiResource(OrderResource::class, Order::class, collection: true, cursorPaginate: 20)]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Order::class);

        $orders = $this->cursorPaginate(
            $this->orderService->buyerOrdersQuery($request->user()),
            $request,
            cursorColumn: 'created_at',
            descending: true,
        );

        return OrderResource::collection($orders);
    }

    #[Doc\Subgroup('Comprador')]
    #[Doc\Endpoint('Detalhar pedido', 'Inclui prazo de pagamento, cópia do endereço, subpedidos com itens, frete, envio, reembolsos, disputa, devolução e histórico de status.')]
    #[Doc\ResponseFromApiResource(OrderResource::class, Order::class, with: ['sellers', 'statusHistories'])]
    public function show(Request $request, Order $order): OrderResource
    {
        $this->ensureBuyerOwnsOrder($request, $order);
        $this->authorize('view', $order);

        return new OrderResource(
            $this->orderService->loadOrder($order),
        );
    }

    #[Doc\Subgroup('Comprador')]
    #[Doc\Endpoint('Cancelar pedido', 'Só em `pending_payment`: libera as reservas e cancela os pagamentos pendentes.')]
    #[Doc\ResponseFromApiResource(OrderResource::class, Order::class)]
    public function cancel(CancelOrderRequest $request, Order $order): OrderResource
    {
        $this->ensureBuyerOwnsOrder($request, $order);
        $this->authorize('cancel', $order);

        return new OrderResource(
            $this->orderService->cancelByBuyer(
                $request->user(),
                $order,
                $request->validated('reason'),
            ),
        );
    }

    private function ensureBuyerOwnsOrder(Request $request, Order $order): void
    {
        $userId = $request->user()?->getAuthIdentifier();

        if ($order->buyer_id !== $userId) {
            throw new NotFoundHttpException('Recurso não encontrado.');
        }
    }
}
