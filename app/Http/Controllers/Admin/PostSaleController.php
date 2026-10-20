<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\DisputeStatus;
use App\Enums\ReturnRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ApproveReturnRequestRequest;
use App\Http\Requests\RejectReturnRequestRequest;
use App\Http\Requests\ResolveDisputeRequest;
use App\Http\Resources\DisputeResource;
use App\Http\Resources\ReturnRequestResource;
use App\Models\Dispute;
use App\Models\ReturnRequest;
use App\Services\DisputeService;
use App\Services\ReturnRequestService;
use App\Support\Pagination\CursorPaginates;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Knuckles\Scribe\Attributes as Doc;

#[Doc\Group('Pós-venda')]
final class PostSaleController extends Controller
{
    use CursorPaginates;

    public function __construct(
        private readonly DisputeService $disputeService,
        private readonly ReturnRequestService $returnRequestService,
    ) {}

    #[Doc\Subgroup('Admin')]
    #[Doc\Endpoint('Listar disputas', 'Filtro opcional por `status`.')]
    #[Doc\Response('{"data":[{"id":"01a1462e-6a1c-73a8-9a51-0e8c2d7f4b10","order_seller_id":"01a1461f-2b3c-7d4e-8f90-1a2b3c4d5e6f","status":"open","reason":"not_as_described","description":"A cor do produto é diferente da anunciada.","seller_response":null,"seller_responded_at":null,"resolution_notes":null,"resolved_at":null,"refund_id":null,"created_at":"2026-10-20T10:00:00-03:00"}],"links":{"first":null,"last":null,"prev":null,"next":null},"meta":{"path":"http://127.0.0.1:8080/api/v1/...","per_page":20,"next_cursor":null,"prev_cursor":null}}', status: 200)]
    public function disputes(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAnyAsAdmin', Dispute::class);

        $status = DisputeStatus::tryFrom((string) $request->query('status'));

        return DisputeResource::collection($this->cursorPaginate(
            Dispute::query()->when($status !== null, fn ($query) => $query->where('status', $status)),
            $request,
            cursorColumn: 'id',
            descending: true,
        ));
    }

    #[Doc\Subgroup('Admin')]
    #[Doc\Endpoint('Resolver disputa', '`refund` reembolsa o valor informado ou todo o restante; `reject` encerra sem reembolso. Em ambos o saldo deixa de ficar travado.')]
    #[Doc\Response('{"data":{"id":"01a1462e-6a1c-73a8-9a51-0e8c2d7f4b10","order_seller_id":"01a1461f-2b3c-7d4e-8f90-1a2b3c4d5e6f","status":"resolved_refunded","reason":"not_as_described","description":"A cor do produto é diferente da anunciada.","seller_response":null,"seller_responded_at":null,"resolution_notes":"Produto divergente do anúncio.","resolved_at":"2026-10-20T10:00:00-03:00","refund_id":"01a14650-4b5c-7d6e-8f90-a1b2c3d4e5f6","created_at":"2026-10-20T10:00:00-03:00"}}', status: 200)]
    public function resolveDispute(ResolveDisputeRequest $request, Dispute $dispute): DisputeResource
    {
        $this->authorize('resolve', $dispute);

        return new DisputeResource($this->disputeService->resolve(
            $request->user(),
            $dispute,
            $request->refundsBuyer(),
            $request->validated('amount_cents'),
            $request->validated('notes'),
        ));
    }

    #[Doc\Subgroup('Admin')]
    #[Doc\Endpoint('Listar devoluções', 'Filtro opcional por `status`.')]
    #[Doc\Response('{"data":[{"id":"01a14630-1d2e-7f40-8a5b-6c7d8e9f0a1b","order_seller_id":"01a1461f-2b3c-7d4e-8f90-1a2b3c4d5e6f","status":"requested","reason":"Não serviu, quero devolver o produto.","amount_cents":9990,"decision_notes":null,"decided_at":null,"refund_id":null,"created_at":"2026-10-20T10:00:00-03:00"}],"links":{"first":null,"last":null,"prev":null,"next":null},"meta":{"path":"http://127.0.0.1:8080/api/v1/...","per_page":20,"next_cursor":null,"prev_cursor":null}}', status: 200)]
    public function returns(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAnyAsAdmin', ReturnRequest::class);

        $status = ReturnRequestStatus::tryFrom((string) $request->query('status'));

        return ReturnRequestResource::collection($this->cursorPaginate(
            ReturnRequest::query()->when($status !== null, fn ($query) => $query->where('status', $status)),
            $request,
            cursorColumn: 'id',
            descending: true,
        ));
    }

    #[Doc\Subgroup('Admin')]
    #[Doc\Endpoint('Aprovar devolução (admin)')]
    #[Doc\Response('{"data":{"id":"01a14630-1d2e-7f40-8a5b-6c7d8e9f0a1b","order_seller_id":"01a1461f-2b3c-7d4e-8f90-1a2b3c4d5e6f","status":"approved","reason":"Não serviu, quero devolver o produto.","amount_cents":9990,"decision_notes":"Seller não respondeu.","decided_at":"2026-10-20T10:00:00-03:00","refund_id":"01a14650-4b5c-7d6e-8f90-a1b2c3d4e5f6","created_at":"2026-10-20T10:00:00-03:00"}}', status: 200)]
    public function approveReturn(ApproveReturnRequestRequest $request, ReturnRequest $returnRequest): ReturnRequestResource
    {
        $this->authorize('decide', $returnRequest);

        return new ReturnRequestResource(
            $this->returnRequestService->approve($request->user(), $returnRequest, $request->validated('notes')),
        );
    }

    #[Doc\Subgroup('Admin')]
    #[Doc\Endpoint('Recusar devolução (admin)')]
    #[Doc\Response('{"data":{"id":"01a14630-1d2e-7f40-8a5b-6c7d8e9f0a1b","order_seller_id":"01a1461f-2b3c-7d4e-8f90-1a2b3c4d5e6f","status":"rejected","reason":"Não serviu, quero devolver o produto.","amount_cents":9990,"decision_notes":"Fora das condições de devolução.","decided_at":"2026-10-20T10:00:00-03:00","refund_id":null,"created_at":"2026-10-20T10:00:00-03:00"}}', status: 200)]
    public function rejectReturn(RejectReturnRequestRequest $request, ReturnRequest $returnRequest): ReturnRequestResource
    {
        $this->authorize('decide', $returnRequest);

        return new ReturnRequestResource(
            $this->returnRequestService->reject($request->user(), $returnRequest, $request->validated('notes')),
        );
    }
}
