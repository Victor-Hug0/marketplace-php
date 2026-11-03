<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminPauseOfferRequest;
use App\Http\Requests\ListAdminOffersRequest;
use App\Http\Resources\OfferResource;
use App\Models\Offer;
use App\Services\OfferService;
use App\Support\Pagination\CursorPaginates;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Knuckles\Scribe\Attributes as Doc;

#[Doc\Group('Ofertas e reservas')]
final class OfferController extends Controller
{
    use CursorPaginates;

    private const RELATIONS = ['product:id,name,slug', 'store:id,store_name,slug,user_id,is_platform'];

    public function __construct(
        private readonly OfferService $offerService,
    ) {}

    #[Doc\Subgroup('Admin')]
    #[Doc\Endpoint('Listar ofertas (admin)', 'Todas as ofertas, de qualquer loja. Filtros opcionais por `status`, `store_id`, `product_id` e `admin_paused`.')]
    #[Doc\ResponseFromApiResource(OfferResource::class, Offer::class, collection: true, cursorPaginate: 20, with: ['product', 'store'])]
    public function index(ListAdminOffersRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Offer::class);

        $filters = $request->validated();

        $query = Offer::query()
            ->with(self::RELATIONS)
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['store_id']), fn ($q) => $q->where('store_id', $filters['store_id']))
            ->when(isset($filters['product_id']), fn ($q) => $q->where('product_id', $filters['product_id']))
            ->when(
                isset($filters['admin_paused']),
                fn ($q) => $request->boolean('admin_paused')
                    ? $q->whereNotNull('admin_paused_at')
                    : $q->whereNull('admin_paused_at'),
            );

        return OfferResource::collection(
            $this->cursorPaginate($query, $request, descending: true),
        );
    }

    #[Doc\Subgroup('Admin')]
    #[Doc\Endpoint('Detalhar oferta (admin)', 'Inclui estoque, reservas e o motivo da trava de moderação.')]
    #[Doc\ResponseFromApiResource(OfferResource::class, Offer::class, with: ['product', 'store'])]
    public function show(Offer $offer): OfferResource
    {
        $this->authorize('moderate', $offer);

        return new OfferResource($offer->load(self::RELATIONS));
    }

    #[Doc\Subgroup('Admin')]
    #[Doc\Endpoint('Pausar oferta pela moderação', 'Tira a oferta da venda, esteja ela `active` ou já `paused` pelo vendedor, e trava a retomada: só um admin reativa. O vendedor é notificado com o motivo. Oferta já travada → 409.')]
    #[Doc\ResponseFromApiResource(OfferResource::class, Offer::class, factoryStates: ['adminPaused'], with: ['product', 'store'])]
    public function pause(AdminPauseOfferRequest $request, Offer $offer): OfferResource
    {
        $this->authorize('moderate', $offer);

        return new OfferResource(
            $this->offerService->pauseAsAdmin(
                $request->user(),
                $offer,
                $request->string('reason')->value(),
            ),
        );
    }

    #[Doc\Subgroup('Admin')]
    #[Doc\Endpoint('Reativar oferta pausada pela moderação', 'Remove a trava e volta a oferta para `active`. Oferta sem trava → 409.')]
    #[Doc\ResponseFromApiResource(OfferResource::class, Offer::class, factoryStates: ['active'], with: ['product', 'store'])]
    public function resume(Request $request, Offer $offer): OfferResource
    {
        $this->authorize('moderate', $offer);

        return new OfferResource(
            $this->offerService->resumeAsAdmin($request->user(), $offer),
        );
    }
}
