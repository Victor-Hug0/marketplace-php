<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use App\Support\Pagination\CursorPaginates;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Knuckles\Scribe\Attributes as Doc;

#[Doc\Group('Pedidos')]
final class OrderController extends Controller
{
    use CursorPaginates;

    public function __construct(
        private readonly OrderService $orderService,
    ) {}

    #[Doc\Subgroup('Admin')]
    #[Doc\Endpoint('Listar pedidos')]
    #[Doc\ResponseFromApiResource(OrderResource::class, Order::class, collection: true, cursorPaginate: 20)]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAnyAsAdmin', Order::class);

        $orders = $this->cursorPaginate(
            $this->orderService->adminOrdersQuery(),
            $request,
            cursorColumn: 'created_at',
            descending: true,
        );

        return OrderResource::collection($orders);
    }

    #[Doc\Subgroup('Admin')]
    #[Doc\Endpoint('Detalhar pedido (admin)')]
    #[Doc\ResponseFromApiResource(OrderResource::class, Order::class, with: ['sellers'])]
    public function show(Order $order): OrderResource
    {
        $this->authorize('view', $order);

        return new OrderResource(
            $this->orderService->loadOrder($order),
        );
    }
}
