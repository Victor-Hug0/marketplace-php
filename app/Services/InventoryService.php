<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OfferStatus;
use App\Enums\ReservationStatus;
use App\Enums\StoreStatus;
use App\Models\InventoryReservation;
use App\Models\Offer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class InventoryService
{
    private const DEFAULT_TTL_MINUTES = 15;

    public function reserve(
        Offer $offer,
        User $user,
        int $quantity,
        int $ttlMinutes = self::DEFAULT_TTL_MINUTES,
    ): InventoryReservation {
        if ($quantity < 1) {
            throw new ConflictHttpException('A quantidade reservada deve ser pelo menos 1.');
        }

        return DB::transaction(function () use ($offer, $user, $quantity, $ttlMinutes): InventoryReservation {
            $offer = Offer::query()
                ->with('store')
                ->lockForUpdate()
                ->findOrFail($offer->id);

            $this->assertOfferReservable($offer);

            if ($offer->availableStock() < $quantity) {
                throw new ConflictHttpException('Estoque insuficiente para reservar a quantidade solicitada.');
            }

            $offer->update([
                'reserved_stock' => $offer->reserved_stock + $quantity,
            ]);

            return InventoryReservation::query()->create([
                'offer_id' => $offer->id,
                'user_id' => $user->id,
                'quantity' => $quantity,
                'status' => ReservationStatus::ACTIVE,
                'expires_at' => now()->addMinutes($ttlMinutes),
            ]);
        });
    }

    public function release(InventoryReservation $reservation): void
    {
        DB::transaction(function () use ($reservation): void {
            $reservation = InventoryReservation::query()
                ->lockForUpdate()
                ->findOrFail($reservation->id);

            if ($reservation->status !== ReservationStatus::ACTIVE) {
                throw new ConflictHttpException('Somente reservas ativas podem ser liberadas.');
            }

            $offer = Offer::query()
                ->lockForUpdate()
                ->findOrFail($reservation->offer_id);

            $offer->update([
                'reserved_stock' => max(0, $offer->reserved_stock - $reservation->quantity),
            ]);

            $reservation->update([
                'status' => ReservationStatus::RELEASED,
            ]);
        });
    }

    public function commit(InventoryReservation $reservation): void
    {
        DB::transaction(function () use ($reservation): void {
            $reservation = InventoryReservation::query()
                ->lockForUpdate()
                ->findOrFail($reservation->id);

            if ($reservation->status !== ReservationStatus::ACTIVE) {
                throw new ConflictHttpException('Somente reservas ativas podem ser confirmadas.');
            }

            $offer = Offer::query()
                ->lockForUpdate()
                ->findOrFail($reservation->offer_id);

            if ($offer->reserved_stock < $reservation->quantity || $offer->stock < $reservation->quantity) {
                throw new ConflictHttpException('Estoque inconsistente para confirmar a reserva.');
            }

            $offer->update([
                'stock' => $offer->stock - $reservation->quantity,
                'reserved_stock' => $offer->reserved_stock - $reservation->quantity,
            ]);

            $reservation->update([
                'status' => ReservationStatus::COMMITTED,
            ]);
        });
    }

    public function releaseExpired(): int
    {
        $expiredIds = InventoryReservation::query()
            ->where('status', ReservationStatus::ACTIVE)
            ->where('expires_at', '<', now())
            ->pluck('id');

        $released = 0;

        foreach ($expiredIds as $id) {
            $reservation = InventoryReservation::query()->find($id);
            if ($reservation === null || $reservation->status !== ReservationStatus::ACTIVE) {
                continue;
            }

            $this->release($reservation);
            $released++;
        }

        return $released;
    }

    private function assertOfferReservable(Offer $offer): void
    {
        if ($offer->status !== OfferStatus::ACTIVE) {
            throw new ConflictHttpException('Ofertas pausadas não podem receber reservas.');
        }

        if ($offer->store->status !== StoreStatus::ACTIVE) {
            throw new ConflictHttpException('A loja da oferta não está ativa.');
        }
    }
}
