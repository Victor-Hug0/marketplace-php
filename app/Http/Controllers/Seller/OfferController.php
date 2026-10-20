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
use Knuckles\Scribe\Attributes as Doc;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Doc\Group('Ofertas e reservas')]
final class OfferController extends Controller
{
    use CursorPaginates;

    public function __construct(
        private readonly OfferService $offerService,
    ) {}

    #[Doc\Subgroup('Seller')]
    #[Doc\Endpoint('Minhas ofertas')]
    #[Doc\ResponseFromApiResource(OfferResource::class, Offer::class, collection: true, cursorPaginate: 20, with: ['product', 'store'])]
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

    #[Doc\Subgroup('Seller')]
    #[Doc\Endpoint('Criar oferta', 'Preço em reais com duas casas. Pode ofertar produto `active` de outra loja; uma oferta por loja e produto (409).')]
    #[Doc\ResponseFromApiResource(OfferResource::class, Offer::class, status: 201, with: ['product', 'store'])]
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

    #[Doc\Subgroup('Seller')]
    #[Doc\Endpoint('Detalhar oferta', 'Inclui `stock` e `reserved_stock`, visíveis só para o dono e o admin.')]
    #[Doc\ResponseFromApiResource(OfferResource::class, Offer::class, with: ['product', 'store'])]
    public function show(Request $request, Offer $offer): OfferResource
    {
        $this->ensureSellerOffer($request, $offer);
        $this->authorize('view', $offer);

        $offer->load(['product:id,name,slug', 'store:id,store_name,slug,user_id']);

        return new OfferResource($offer);
    }

    #[Doc\Subgroup('Seller')]
    #[Doc\Endpoint('Atualizar oferta', '`stock` não pode ficar abaixo do reservado (409).')]
    #[Doc\ResponseFromApiResource(OfferResource::class, Offer::class, with: ['product', 'store'])]
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

    #[Doc\Subgroup('Seller')]
    #[Doc\Endpoint('Remover oferta', 'Soft delete; bloqueado com reservas ativas (409).')]
    #[Doc\Response('', status: 204)]
    public function destroy(Request $request, Offer $offer): JsonResponse
    {
        $this->ensureSellerOffer($request, $offer);
        $this->authorize('delete', $offer);

        $this->offerService->delete($request->user(), $offer);

        return response()->json(null, 204);
    }

    #[Doc\Subgroup('Seller')]
    #[Doc\Endpoint('Pausar oferta', '`active → paused`; sai do Buy Box e da compra.')]
    #[Doc\ResponseFromApiResource(OfferResource::class, Offer::class, factoryStates: ['paused'])]
    public function pause(Request $request, Offer $offer): OfferResource
    {
        $this->ensureSellerOffer($request, $offer);
        $this->authorize('pause', $offer);

        return new OfferResource(
            $this->offerService->pause($request->user(), $offer),
        );
    }

    #[Doc\Subgroup('Seller')]
    #[Doc\Endpoint('Retomar oferta', '`paused → active`.')]
    #[Doc\ResponseFromApiResource(OfferResource::class, Offer::class, factoryStates: ['active'])]
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
