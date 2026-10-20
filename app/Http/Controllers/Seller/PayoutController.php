<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePayoutRequest;
use App\Http\Resources\PayoutResource;
use App\Models\Payout;
use App\Models\Store;
use App\Services\PayoutService;
use App\Support\Pagination\CursorPaginates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Knuckles\Scribe\Attributes as Doc;

#[Doc\Group('Financeiro')]
final class PayoutController extends Controller
{
    use CursorPaginates;

    public function __construct(
        private readonly PayoutService $payoutService,
    ) {}

    #[Doc\Subgroup('Seller')]
    #[Doc\Endpoint('Meus saques')]
    #[Doc\Response('{"data":[{"id":"01a14640-9a8b-7c6d-5e4f-3a2b1c0d9e8f","store_id":"01a14513-0e23-726f-b299-104a96511ebb","status":"requested","amount_cents":5000,"currency":"BRL","failure_reason":null,"paid_at":null,"failed_at":null,"created_at":"2026-10-20T10:00:00-03:00","updated_at":"2026-10-20T10:00:00-03:00"}],"links":{"first":null,"last":null,"prev":null,"next":null},"meta":{"path":"http://127.0.0.1:8080/api/v1/...","per_page":20,"next_cursor":null,"prev_cursor":null}}', status: 200)]
    public function index(Request $request): AnonymousResourceCollection
    {
        $store = Store::query()
            ->whereBelongsTo($request->user(), 'owner')
            ->firstOrFail();

        $this->authorize('view', $store);

        $payouts = $this->cursorPaginate(
            $this->payoutService->storePayoutsQuery($store),
            $request,
            cursorColumn: 'id',
            descending: true,
        );

        return PayoutResource::collection($payouts);
    }

    #[Doc\Subgroup('Seller')]
    #[Doc\Endpoint('Solicitar saque', 'Até o valor disponível; exige loja `active` e e-mail confirmado (403). Mesma `Idempotency-Key` devolve o saque existente com 200.')]
    #[Doc\Header('Idempotency-Key', 'f3c1b2a4-0001')]
    #[Doc\Response('{"data":{"id":"01a14640-9a8b-7c6d-5e4f-3a2b1c0d9e8f","store_id":"01a14513-0e23-726f-b299-104a96511ebb","status":"requested","amount_cents":5000,"currency":"BRL","failure_reason":null,"paid_at":null,"failed_at":null,"created_at":"2026-10-20T10:00:00-03:00","updated_at":"2026-10-20T10:00:00-03:00"},"message":"Saque solicitado com sucesso."}', status: 201)]
    #[Doc\Response('{"message":"Saldo disponível insuficiente para o saque.","timestamp":"2026-10-20T10:00:00-03:00","errors":[]}', status: 409)]
    public function store(StorePayoutRequest $request): JsonResponse
    {
        $this->authorize('request', Payout::class);

        $result = $this->payoutService->request(
            $request->user(),
            $request->integer('amount_cents'),
            $request->idempotencyKey(),
        );

        $created = $result['created'];

        return (new PayoutResource($result['payout']))
            ->additional([
                'message' => $created
                    ? 'Saque solicitado com sucesso.'
                    : 'Saque já existente para esta chave de idempotência.',
            ])
            ->response()
            ->setStatusCode($created ? 201 : 200);
    }
}
