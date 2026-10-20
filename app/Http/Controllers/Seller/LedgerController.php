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
use Knuckles\Scribe\Attributes as Doc;

#[Doc\Group('Financeiro')]
final class LedgerController extends Controller
{
    use CursorPaginates;

    public function __construct(
        private readonly LedgerService $ledgerService,
    ) {}

    #[Doc\Subgroup('Seller')]
    #[Doc\Endpoint('Saldo da loja', '`available_cents` = saldo − saques pendentes − valor travado por disputa aberta ou devolução pendente.')]
    #[Doc\Response('{"data":{"store_id":"01a14513-0e23-726f-b299-104a96511ebb","currency":"BRL","balance_cents":8991,"pending_payout_cents":0,"held_cents":0,"available_cents":8991}}', status: 200)]
    public function balance(Request $request): JsonResponse
    {
        $store = $this->ownStore($request);

        $balance = $this->ledgerService->sellerBalanceCents($store);
        $pendingPayouts = $this->ledgerService->pendingPayoutCents($store);
        $held = $this->ledgerService->heldBalanceCents($store);

        return new JsonResponse([
            'data' => [
                'store_id' => $store->id,
                'currency' => 'BRL',
                'balance_cents' => $balance,
                'pending_payout_cents' => $pendingPayouts,
                'held_cents' => $held,
                'available_cents' => $balance - $pendingPayouts - $held,
            ],
        ]);
    }

    #[Doc\Subgroup('Seller')]
    #[Doc\Endpoint('Lançamentos do ledger', 'Vendas, comissões estornadas, reembolsos e saques da loja, mais recentes primeiro.')]
    #[Doc\Response('{"data":[{"id":"01a14560-1a2b-7c3d-8e4f-5a6b7c8d9e0f","account_type":"seller","reason":"sale","amount_cents":8991,"currency":"BRL","order_seller_id":"01a1461f-2b3c-7d4e-8f90-1a2b3c4d5e6f","refund_id":null,"payout_id":null,"commission":{"base_cents":9990,"fee_cents":999,"fee_type":"percentage","percent_bps":1000,"fixed_cents":null},"created_at":"2026-10-20T10:00:00-03:00"},{"id":"01a14661-2b3c-7d4e-8f5a-6b7c8d9e0f1a","account_type":"seller","reason":"payout","amount_cents":-5000,"currency":"BRL","order_seller_id":null,"refund_id":null,"payout_id":"01a14640-9a8b-7c6d-5e4f-3a2b1c0d9e8f","commission":{"base_cents":null,"fee_cents":null,"fee_type":null,"percent_bps":null,"fixed_cents":null},"created_at":"2026-10-20T10:00:00-03:00"}],"links":{"first":null,"last":null,"prev":null,"next":null},"meta":{"path":"http://127.0.0.1:8080/api/v1/...","per_page":20,"next_cursor":null,"prev_cursor":null}}', status: 200)]
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
