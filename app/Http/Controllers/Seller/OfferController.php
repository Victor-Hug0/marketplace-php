<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOfferRequest;
use App\Http\Requests\UpdateOfferRequest;
use App\Http\Resources\OfferResource;
use App\Models\Offer;
use App\Services\OfferService;
use App\Support\Pagination\CursorPaginates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class OfferController extends Controller
{
    use CursorPaginates;

    public function __construct(
        private readonly OfferService $offerService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Offer::class);

        $store = $request->user()->store;
        abort_if($store === null, 403, 'Usuário não autorizado.');

        $offers = $this->cursorPaginate(
            Offer::query()
                ->where('store_id', $store->id)
                ->with(['product:id,name,slug', 'store:id,store_name,slug,user_id']),
            $request,
        );

        return OfferResource::collection($offers);
    }

    public function store(StoreOfferRequest $request): JsonResponse
    {
        $store = $request->user()->store;
        abort_if($store === null, 403, 'Usuário não autorizado.');

        $offer = $this->offerService->create(
            $request->user(),
            $store,
            $request->validated(),
        );

        return (new OfferResource($offer))
            ->additional(['message' => 'Oferta criada com sucesso.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Offer $offer): OfferResource
    {
        $this->ensureSellerOffer($request, $offer);
        $this->authorize('view', $offer);

        $offer->load(['product:id,name,slug', 'store:id,store_name,slug,user_id']);

        return new OfferResource($offer);
    }

    public function update(UpdateOfferRequest $request, Offer $offer): OfferResource
    {
        $this->ensureSellerOffer($request, $offer);

        return new OfferResource(
            $this->offerService->update(
                $request->user(),
                $offer,
                $request->validated(),
            ),
        );
    }

    public function destroy(Request $request, Offer $offer): JsonResponse
    {
        $this->ensureSellerOffer($request, $offer);
        $this->authorize('delete', $offer);

        $this->offerService->delete($request->user(), $offer);

        return response()->json(null, 204);
    }

    public function pause(Request $request, Offer $offer): OfferResource
    {
        $this->ensureSellerOffer($request, $offer);
        $this->authorize('pause', $offer);

        return new OfferResource(
            $this->offerService->pause($request->user(), $offer),
        );
    }

    public function resume(Request $request, Offer $offer): OfferResource
    {
        $this->ensureSellerOffer($request, $offer);
        $this->authorize('resume', $offer);

        return new OfferResource(
            $this->offerService->resume($request->user(), $offer),
        );
    }

    private function ensureSellerOffer(Request $request, Offer $offer): void
    {
        $store = $request->user()?->store;

        if ($store === null || $offer->store_id !== $store->id) {
            throw new NotFoundHttpException('Recurso não encontrado.');
        }
    }
}
