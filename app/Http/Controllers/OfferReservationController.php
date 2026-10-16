<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreReservationRequest;
use App\Http\Resources\InventoryReservationResource;
use App\Models\InventoryReservation;
use App\Models\Offer;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class OfferReservationController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventoryService,
    ) {}

    public function store(StoreReservationRequest $request, Offer $offer): JsonResponse
    {
        $reservation = $this->inventoryService->reserve(
            $offer,
            $request->user(),
            (int) $request->validated('quantity'),
        );

        return (new InventoryReservationResource($reservation))
            ->additional(['message' => 'Estoque reservado com sucesso.'])
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(
        Request $request,
        Offer $offer,
        InventoryReservation $reservation,
    ): JsonResponse {
        if ($reservation->offer_id !== $offer->id) {
            throw new NotFoundHttpException('Reserva não encontrada para esta oferta.');
        }

        if ($reservation->user_id !== $request->user()->id) {
            throw new AccessDeniedHttpException('Usuário não autorizado.');
        }

        $this->inventoryService->release($reservation);

        return response()->json(null, 204);
    }
}
