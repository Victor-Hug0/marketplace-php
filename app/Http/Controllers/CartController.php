<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreCartItemRequest;
use App\Http\Requests\UpdateCartItemRequest;
use App\Http\Resources\CartResource;
use App\Models\Cart;
use App\Models\CartItem;
use App\Services\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes as Doc;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Doc\Group('Carrinho')]
final class CartController extends Controller
{
    public function __construct(
        private readonly CartService $cartService,
    ) {}

    #[Doc\Endpoint('Ver carrinho', 'Totais calculados com o preço vigente de cada oferta, em reais.')]
    #[Doc\ResponseFromApiResource(CartResource::class, Cart::class, with: ['items'])]
    public function show(Request $request): JsonResponse
    {
        $cart = $this->cartService->getCart($request->user());
        $this->authorize('view', $cart);

        return (new CartResource($cart))
            ->response()
            ->setStatusCode(200);
    }

    #[Doc\Endpoint('Adicionar item', 'A mesma oferta soma a quantidade (até 999). Oferta, loja e produto precisam estar `active` e com estoque (409).')]
    #[Doc\ResponseFromApiResource(CartResource::class, Cart::class, status: 201, with: ['items'])]
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

    #[Doc\Endpoint('Alterar quantidade')]
    #[Doc\ResponseFromApiResource(CartResource::class, Cart::class, with: ['items'])]
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

    #[Doc\Endpoint('Remover item')]
    #[Doc\ResponseFromApiResource(CartResource::class, Cart::class, with: ['items'])]
    public function destroyItem(Request $request, CartItem $item): CartResource
    {
        $this->ensureCartItemOwner($request, $item);
        $this->authorize('delete', $item);

        return new CartResource(
            $this->cartService->removeItem($request->user(), $item),
        );
    }

    #[Doc\Endpoint('Esvaziar carrinho')]
    #[Doc\Response('', status: 204)]
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
