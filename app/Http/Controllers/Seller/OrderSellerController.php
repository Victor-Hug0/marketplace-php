<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Requests\CancelOrderRequest;
use App\Http\Resources\OrderSellerResource;
use App\Models\OrderSeller;
use App\Services\OrderService;
use App\Support\Pagination\CursorPaginates;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Knuckles\Scribe\Attributes as Doc;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Doc\Group('Pedidos')]
final class OrderSellerController extends Controller
{
    use CursorPaginates;

    public function __construct(
        private readonly OrderService $orderService,
    ) {}

    #[Doc\Subgroup('Seller')]
    #[Doc\Endpoint('Meus subpedidos', 'Subpedidos da própria loja.')]
    #[Doc\ResponseFromApiResource(OrderSellerResource::class, OrderSeller::class, collection: true, cursorPaginate: 20, with: ['items'])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', OrderSeller::class);

        $orderSellers = $this->cursorPaginate(
            $this->orderService->sellerOrderSellersQuery($request->user()),
            $request,
            cursorColumn: 'created_at',
            descending: true,
        );

        return OrderSellerResource::collection($orderSellers);
    }

    #[Doc\Subgroup('Seller')]
    #[Doc\Endpoint('Detalhar subpedido')]
    #[Doc\ResponseFromApiResource(OrderSellerResource::class, OrderSeller::class, with: ['items', 'shipment'])]
    public function show(Request $request, OrderSeller $orderSeller): OrderSellerResource
    {
        $this->ensureSellerOwns($request, $orderSeller);
        $this->authorize('view', $orderSeller);

        return new OrderSellerResource(
            $this->orderService->loadOrderSeller($orderSeller),
        );
    }

    #[Doc\Subgroup('Seller')]
    #[Doc\Endpoint('Cancelar subpedido', 'Até `processing`. Se já pago, reembolsa o restante e repõe o estoque.')]
    #[Doc\ResponseFromApiResource(OrderSellerResource::class, OrderSeller::class)]
    public function cancel(
        CancelOrderRequest $request,
        OrderSeller $orderSeller,
    ): OrderSellerResource {
        $this->ensureSellerOwns($request, $orderSeller);
        $this->authorize('cancel', $orderSeller);

        return new OrderSellerResource(
            $this->orderService->cancelBySeller(
                $request->user(),
                $orderSeller,
                $request->validated('reason'),
            ),
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
