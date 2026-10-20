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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class OrderSellerController extends Controller
{
    use CursorPaginates;

    public function __construct(
        private readonly OrderService $orderService,
    ) {}

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

    public function show(Request $request, OrderSeller $orderSeller): OrderSellerResource
    {
        $this->ensureSellerOwns($request, $orderSeller);
        $this->authorize('view', $orderSeller);

        return new OrderSellerResource(
            $this->orderService->loadOrderSeller($orderSeller),
        );
    }

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
