<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OfferStatus;
use App\Enums\ProductStatus;
use App\Enums\StoreStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Offer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CartService
{
    public function getOrCreateCart(User $user): Cart
    {
        return Cart::query()->firstOrCreate([
            'user_id' => $user->id,
        ]);
    }

    public function getCart(User $user): Cart
    {
        $cart = $this->getOrCreateCart($user);

        return $this->loadCart($cart);
    }

    public function addItem(User $user, string $offerId, int $quantity): Cart
    {
        return DB::transaction(function () use ($user, $offerId, $quantity): Cart {
            $cart = Cart::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($cart === null) {
                $cart = Cart::query()->create(['user_id' => $user->id]);
                $cart = Cart::query()->lockForUpdate()->findOrFail($cart->id);
            }

            $offer = $this->lockedPurchasableOffer($offerId);

            $item = CartItem::query()
                ->where('cart_id', $cart->id)
                ->where('offer_id', $offer->id)
                ->lockForUpdate()
                ->first();

            $newQuantity = ($item?->quantity ?? 0) + $quantity;
            $this->assertQuantityAvailable($offer, $newQuantity);

            if ($item === null) {
                CartItem::query()->create([
                    'cart_id' => $cart->id,
                    'offer_id' => $offer->id,
                    'quantity' => $newQuantity,
                ]);
            } else {
                $item->update(['quantity' => $newQuantity]);
            }

            return $this->loadCart($cart->refresh());
        });
    }

    public function updateItem(User $user, CartItem $item, int $quantity): Cart
    {
        return DB::transaction(function () use ($user, $item, $quantity): Cart {
            $cart = $this->lockedUserCart($user);
            $item = $this->lockedCartItem($cart, $item);
            $offer = $this->lockedPurchasableOffer($item->offer_id);

            $this->assertQuantityAvailable($offer, $quantity);

            $item->update(['quantity' => $quantity]);

            return $this->loadCart($cart->refresh());
        });
    }

    public function removeItem(User $user, CartItem $item): Cart
    {
        return DB::transaction(function () use ($user, $item): Cart {
            $cart = $this->lockedUserCart($user);
            $item = $this->lockedCartItem($cart, $item);
            $item->delete();

            return $this->loadCart($cart->refresh());
        });
    }

    public function clear(User $user): Cart
    {
        return DB::transaction(function () use ($user): Cart {
            $cart = $this->lockedUserCart($user);
            $cart->items()->delete();

            return $this->loadCart($cart->refresh());
        });
    }

    private function lockedUserCart(User $user): Cart
    {
        $cart = Cart::query()
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->first();

        if ($cart === null) {
            throw new NotFoundHttpException('Carrinho não encontrado.');
        }

        return $cart;
    }

    private function lockedCartItem(Cart $cart, CartItem $item): CartItem
    {
        $locked = CartItem::query()
            ->where('cart_id', $cart->id)
            ->whereKey($item->id)
            ->lockForUpdate()
            ->first();

        if ($locked === null) {
            throw new NotFoundHttpException('Item do carrinho não encontrado.');
        }

        return $locked;
    }

    private function lockedPurchasableOffer(string $offerId): Offer
    {
        $offer = Offer::query()
            ->with(['store', 'product'])
            ->lockForUpdate()
            ->find($offerId);

        if ($offer === null) {
            throw new NotFoundHttpException('Oferta não encontrada.');
        }

        if ($offer->status !== OfferStatus::ACTIVE) {
            throw new ConflictHttpException('A oferta não está disponível para compra.');
        }

        if ($offer->store === null || $offer->store->status !== StoreStatus::ACTIVE) {
            throw new ConflictHttpException('A loja da oferta não está ativa.');
        }

        if ($offer->product === null || $offer->product->status !== ProductStatus::ACTIVE) {
            throw new ConflictHttpException('O produto da oferta não está ativo.');
        }

        return $offer;
    }

    private function assertQuantityAvailable(Offer $offer, int $quantity): void
    {
        if ($quantity < 1) {
            throw new ConflictHttpException('A quantidade deve ser no mínimo 1.');
        }

        if ($offer->availableStock() < $quantity) {
            throw new ConflictHttpException(
                'Estoque insuficiente para a quantidade solicitada.',
            );
        }
    }

    private function loadCart(Cart $cart): Cart
    {
        return $cart->load([
            'items.offer.store:id,store_name,slug,status,user_id',
            'items.offer.product:id,name,slug,status',
        ]);
    }
}
