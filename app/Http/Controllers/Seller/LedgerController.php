<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Resources\LedgerEntryResource;
use App\Models\Store;
use App\Services\LedgerService;
use App\Support\Pagination\CursorPaginates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class LedgerController extends Controller
{
    use CursorPaginates;

    public function __construct(
        private readonly LedgerService $ledgerService,
    ) {}

    public function balance(Request $request): JsonResponse
    {
        $store = $this->ownStore($request);

        return new JsonResponse([
            'data' => [
                'store_id' => $store->id,
                'currency' => 'BRL',
                'balance_cents' => $this->ledgerService->sellerBalanceCents($store),
            ],
        ]);
    }

    public function entries(Request $request): AnonymousResourceCollection
    {
        $store = $this->ownStore($request);

        $entries = $this->cursorPaginate(
            $this->ledgerService->sellerEntriesQuery($store),
            $request,
            cursorColumn: 'id',
            descending: true,
        );

        return LedgerEntryResource::collection($entries);
    }

    private function ownStore(Request $request): Store
    {
        $store = Store::query()
            ->whereBelongsTo($request->user(), 'owner')
            ->firstOrFail();

        $this->authorize('view', $store);

        return $store;
    }
}
