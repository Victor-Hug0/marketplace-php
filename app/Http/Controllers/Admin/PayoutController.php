<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\PayoutStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\FailPayoutRequest;
use App\Http\Resources\PayoutResource;
use App\Models\Payout;
use App\Services\PayoutService;
use App\Support\Pagination\CursorPaginates;
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

    #[Doc\Subgroup('Admin')]
    #[Doc\Endpoint('Listar saques', 'Filtro opcional por `status` (`requested`, `paid`, `failed`).')]
    #[Doc\Response('{"data":[{"id":"01a14640-9a8b-7c6d-5e4f-3a2b1c0d9e8f","store_id":"01a14513-0e23-726f-b299-104a96511ebb","status":"requested","amount_cents":5000,"currency":"BRL","failure_reason":null,"paid_at":null,"failed_at":null,"created_at":"2026-10-20T10:00:00-03:00","updated_at":"2026-10-20T10:00:00-03:00","store":{"id":"01a14513-0e23-726f-b299-104a96511ebb","store_name":"Loja Demo","slug":"loja-demo"}}],"links":{"first":null,"last":null,"prev":null,"next":null},"meta":{"path":"http://127.0.0.1:8080/api/v1/...","per_page":20,"next_cursor":null,"prev_cursor":null}}', status: 200)]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAnyAsAdmin', Payout::class);

        $payouts = $this->cursorPaginate(
            $this->payoutService->adminPayoutsQuery(
                PayoutStatus::tryFrom($request->string('status')->value()),
            ),
            $request,
            cursorColumn: 'id',
            descending: true,
        );

        return PayoutResource::collection($payouts);
    }

    #[Doc\Subgroup('Admin')]
    #[Doc\Endpoint('Marcar saque como pago', 'Gera o lançamento negativo no ledger.')]
    #[Doc\Response('{"data":{"id":"01a14640-9a8b-7c6d-5e4f-3a2b1c0d9e8f","store_id":"01a14513-0e23-726f-b299-104a96511ebb","status":"paid","amount_cents":5000,"currency":"BRL","failure_reason":null,"paid_at":"2026-10-20T10:00:00-03:00","failed_at":null,"created_at":"2026-10-20T10:00:00-03:00","updated_at":"2026-10-20T10:00:00-03:00"}}', status: 200)]
    public function markPaid(Request $request, Payout $payout): PayoutResource
    {
        $this->authorize('process', $payout);

        return new PayoutResource(
            $this->payoutService->markPaid($request->user(), $payout),
        );
    }

    #[Doc\Subgroup('Admin')]
    #[Doc\Endpoint('Marcar saque como falho', 'O valor volta ao disponível, sem lançamento.')]
    #[Doc\Response('{"data":{"id":"01a14640-9a8b-7c6d-5e4f-3a2b1c0d9e8f","store_id":"01a14513-0e23-726f-b299-104a96511ebb","status":"failed","amount_cents":5000,"currency":"BRL","failure_reason":"Conta bancária inválida.","paid_at":null,"failed_at":"2026-10-20T10:00:00-03:00","created_at":"2026-10-20T10:00:00-03:00","updated_at":"2026-10-20T10:00:00-03:00"}}', status: 200)]
    public function markFailed(FailPayoutRequest $request, Payout $payout): PayoutResource
    {
        $this->authorize('process', $payout);

        return new PayoutResource(
            $this->payoutService->markFailed(
                $request->user(),
                $payout,
                $request->string('reason')->value(),
            ),
        );
    }
}
