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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class OrderController extends Controller
{
    use CursorPaginates;

    public function __construct(
        private readonly OrderService $orderService,
    ) {}

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

    public function show(Request $request, Order $order): OrderResource
    {
        $this->ensureBuyerOwnsOrder($request, $order);
        $this->authorize('view', $order);

        return new OrderResource(
            $this->orderService->loadOrder($order),
        );
    }

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
