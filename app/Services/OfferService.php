<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OfferStatus;
use App\Enums\ProductStatus;
use App\Enums\RoleName;
use App\Enums\StoreStatus;
use App\Models\AuditLog;
use App\Models\Offer;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class OfferService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, Store $store, array $data): Offer
    {
        $this->assertSellerCanWrite($actor, $store);
        $product = $this->resolveActiveProduct($data['product_id']);

        return DB::transaction(function () use ($actor, $store, $product, $data): Offer {
            $this->assertUniqueOffer($product->id, $store->id);
            $this->assertPricing($data);

            $offer = Offer::query()->create([
                'product_id' => $product->id,
                'store_id' => $store->id,
                'price' => $data['price'],
                'promotional_price' => $data['promotional_price'] ?? null,
                'promo_starts_at' => $data['promo_starts_at'] ?? null,
                'promo_ends_at' => $data['promo_ends_at'] ?? null,
                'stock' => $data['stock'],
                'reserved_stock' => 0,
                'status' => OfferStatus::ACTIVE,
            ]);

            $this->audit($actor, $offer, 'offer.created', [
                'product_id' => $product->id,
                'store_id' => $store->id,
                'price' => (string) $offer->price,
                'stock' => $offer->stock,
            ]);

            return $offer->load(['product', 'store']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, Offer $offer, array $data): Offer
    {
        return DB::transaction(function () use ($actor, $offer, $data): Offer {
            $offer = $this->lockedOffer($offer);
            $this->assertSellerOwnsOffer($actor, $offer);
            $this->assertSellerCanWrite($actor, $offer->store);

            $merged = array_merge([
                'price' => $offer->price,
                'promotional_price' => $offer->promotional_price,
                'promo_starts_at' => $offer->promo_starts_at?->toIso8601String(),
                'promo_ends_at' => $offer->promo_ends_at?->toIso8601String(),
                'stock' => $offer->stock,
            ], $data);

            $this->assertPricing($merged);

            if (array_key_exists('stock', $data) && (int) $data['stock'] < $offer->reserved_stock) {
                throw new ConflictHttpException(
                    'O estoque não pode ser menor que a quantidade já reservada.',
                );
            }

            $offer->update(array_intersect_key($data, array_flip([
                'price',
                'promotional_price',
                'promo_starts_at',
                'promo_ends_at',
                'stock',
            ])));

            $this->audit($actor, $offer, 'offer.updated', [
                'price' => (string) $offer->price,
                'stock' => $offer->stock,
            ]);

            return $offer->refresh()->load(['product', 'store']);
        });
    }

    public function pause(User $actor, Offer $offer, bool $asAdmin = false): Offer
    {
        return DB::transaction(function () use ($actor, $offer, $asAdmin): Offer {
            $offer = $this->lockedOffer($offer);

            if ($asAdmin) {
                $this->assertAdmin($actor);
            } else {
                $this->assertSellerOwnsOffer($actor, $offer);
                $this->assertSellerCanWrite($actor, $offer->store);
            }

            if ($offer->status !== OfferStatus::ACTIVE) {
                throw new ConflictHttpException(
                    'A oferta precisa estar active para ser pausada.',
                );
            }

            $offer->update(['status' => OfferStatus::PAUSED]);
            $this->audit($actor, $offer, 'offer.paused');

            return $offer->refresh()->load(['product', 'store']);
        });
    }

    public function resume(User $actor, Offer $offer): Offer
    {
        return DB::transaction(function () use ($actor, $offer): Offer {
            $offer = $this->lockedOffer($offer);
            $this->assertSellerOwnsOffer($actor, $offer);
            $this->assertSellerCanWrite($actor, $offer->store);

            if ($offer->status !== OfferStatus::PAUSED) {
                throw new ConflictHttpException(
                    'A oferta precisa estar paused para ser reativada.',
                );
            }

            $offer->update(['status' => OfferStatus::ACTIVE]);
            $this->audit($actor, $offer, 'offer.resumed');

            return $offer->refresh()->load(['product', 'store']);
        });
    }

    public function delete(User $actor, Offer $offer): void
    {
        DB::transaction(function () use ($actor, $offer): void {
            $offer = $this->lockedOffer($offer);
            $this->assertSellerOwnsOffer($actor, $offer);
            $this->assertSellerCanWrite($actor, $offer->store);

            if ($offer->reserved_stock > 0) {
                throw new ConflictHttpException(
                    'Não é possível remover uma oferta com reservas ativas.',
                );
            }

            $offer->delete();
            $this->audit($actor, $offer, 'offer.deleted', [
                'product_id' => $offer->product_id,
                'store_id' => $offer->store_id,
            ]);
        });
    }

    private function lockedOffer(Offer $offer): Offer
    {
        return Offer::query()
            ->with('store')
            ->lockForUpdate()
            ->findOrFail($offer->id);
    }

    private function resolveActiveProduct(string $productId): Product
    {
        $product = Product::query()
            ->whereKey($productId)
            ->where('status', ProductStatus::ACTIVE)
            ->first();

        if ($product === null) {
            throw new ConflictHttpException('O produto informado é inválido ou não está ativo.');
        }

        return $product;
    }

    private function assertUniqueOffer(string $productId, string $storeId): void
    {
        $exists = Offer::withTrashed()
            ->where('product_id', $productId)
            ->where('store_id', $storeId)
            ->exists();

        if ($exists) {
            throw new ConflictHttpException(
                'Esta loja já possui uma oferta para este produto.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertPricing(array $data): void
    {
        $price = (string) $data['price'];
        if (bccomp($price, '0', 2) <= 0) {
            throw new ConflictHttpException('O preço deve ser maior que zero.');
        }

        $promo = $data['promotional_price'] ?? null;
        if ($promo === null || $promo === '') {
            return;
        }

        if (bccomp((string) $promo, '0', 2) <= 0) {
            throw new ConflictHttpException('O preço promocional deve ser maior que zero.');
        }

        if (bccomp((string) $promo, $price, 2) >= 0) {
            throw new ConflictHttpException(
                'O preço promocional deve ser menor que o preço regular.',
            );
        }
    }

    private function assertSellerOwnsOffer(User $actor, Offer $offer): void
    {
        if ($offer->store->user_id !== $actor->id) {
            throw new AccessDeniedHttpException('Usuário não autorizado.');
        }
    }

    private function assertSellerCanWrite(User $actor, Store $store): void
    {
        if (! $actor->hasRole(RoleName::SELLER)) {
            throw new AccessDeniedHttpException('Usuário não autorizado.');
        }

        if ($store->user_id !== $actor->id || $store->status !== StoreStatus::ACTIVE) {
            throw new AccessDeniedHttpException(
                'Somente sellers com loja ativa podem gerenciar ofertas.',
            );
        }
    }

    private function assertAdmin(User $actor): void
    {
        if (! $actor->hasRole(RoleName::ADMIN)) {
            throw new AccessDeniedHttpException('Usuário não autorizado.');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function audit(User $actor, Offer $offer, string $action, array $payload = []): void
    {
        AuditLog::query()->create([
            'action' => $action,
            'actor_id' => $actor->id,
            'actor_type' => $actor->getMorphClass(),
            'auditable_id' => $offer->id,
            'auditable_type' => $offer->getMorphClass(),
            'payload' => $payload,
            'created_at' => now(),
        ]);
    }
}
