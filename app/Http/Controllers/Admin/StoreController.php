<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStatusReasonRequest;
use App\Http\Resources\StoreResource;
use App\Models\Store;
use App\Services\StoreService;
use App\Support\Pagination\CursorPaginates;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Knuckles\Scribe\Attributes as Doc;

#[Doc\Group('Lojas')]
final class StoreController extends Controller
{
    use CursorPaginates;

    public function __construct(
        private readonly StoreService $storeService,
    ) {}

    #[Doc\Subgroup('Admin')]
    #[Doc\Endpoint('Listar lojas', 'Todas as lojas com o dono, paginadas por cursor.')]
    #[Doc\ResponseFromApiResource(StoreResource::class, Store::class, collection: true, cursorPaginate: 20, with: ['owner'])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Store::class);

        $stores = $this->cursorPaginate(
            Store::query()->with('owner'),
            $request,
        );

        return StoreResource::collection($stores);
    }

    #[Doc\Subgroup('Admin')]
    #[Doc\Endpoint('Aprovar loja', '`pending → active`; o dono ganha o papel `seller`.')]
    #[Doc\ResponseFromApiResource(StoreResource::class, Store::class, factoryStates: ['active'])]
    public function approve(Request $request, Store $store): StoreResource
    {
        $this->authorize('review', $store);

        return new StoreResource(
            $this->storeService->approve($store, $request->user()),
        );
    }

    #[Doc\Subgroup('Admin')]
    #[Doc\Endpoint('Rejeitar loja', '`pending → rejected` com motivo (10 a 2000 caracteres).')]
    #[Doc\ResponseFromApiResource(StoreResource::class, Store::class)]
    public function reject(
        StoreStatusReasonRequest $request,
        Store $store,
    ): StoreResource {
        $this->authorize('review', $store);

        return new StoreResource(
            $this->storeService->reject(
                $store,
                $request->user(),
                $request->string('reason')->value(),
            ),
        );
    }

    #[Doc\Subgroup('Admin')]
    #[Doc\Endpoint('Suspender loja', '`active → suspended` com motivo; o dono perde o papel `seller` e as ofertas saem da compra.')]
    #[Doc\ResponseFromApiResource(StoreResource::class, Store::class)]
    public function suspend(
        StoreStatusReasonRequest $request,
        Store $store,
    ): StoreResource {
        $this->authorize('review', $store);

        return new StoreResource(
            $this->storeService->suspend(
                $store,
                $request->user(),
                $request->string('reason')->value(),
            ),
        );
    }
}
