<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreCartItemRequest;
use App\Http\Requests\UpdateCartItemRequest;
use App\Http\Resources\CartResource;
use App\Models\CartItem;
use App\Services\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CartController extends Controller
{
    public function __construct(
        private readonly CartService $cartService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $cart = $this->cartService->getCart($request->user());
        $this->authorize('view', $cart);

        return (new CartResource($cart))
            ->response()
            ->setStatusCode(200);
    }

    public function storeItem(StoreCartItemRequest $request): JsonResponse
    {
        $cart = $this->cartService->addItem(
            $request->user(),
            $request->string('offer_id')->value(),
            $request->integer('quantity'),
        );

        $this->authorize('view', $cart);

        return (new CartResource($cart))
            ->additional(['message' => 'Item adicionado ao carrinho.'])
            ->response()
            ->setStatusCode(201);
    }

    public function updateItem(
        UpdateCartItemRequest $request,
        CartItem $item,
    ): CartResource {
        $this->ensureCartItemOwner($request, $item);
        $this->authorize('update', $item);

        return new CartResource(
            $this->cartService->updateItem(
                $request->user(),
                $item,
                $request->integer('quantity'),
            ),
        );
    }

    public function destroyItem(Request $request, CartItem $item): CartResource
    {
        $this->ensureCartItemOwner($request, $item);
        $this->authorize('delete', $item);

        return new CartResource(
            $this->cartService->removeItem($request->user(), $item),
        );
    }

    public function clear(Request $request): JsonResponse
    {
        $cart = $this->cartService->getOrCreateCart($request->user());
        $this->authorize('update', $cart);

        $this->cartService->clear($request->user());

        return response()->json(null, 204);
    }

    private function ensureCartItemOwner(Request $request, CartItem $item): void
    {
        $item->loadMissing('cart');

        if ($item->cart?->user_id !== $request->user()?->id) {
            throw new NotFoundHttpException('Recurso não encontrado.');
        }
    }
}
