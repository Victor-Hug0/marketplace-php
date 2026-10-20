<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApproveReturnRequestRequest;
use App\Http\Requests\RejectReturnRequestRequest;
use App\Http\Requests\RespondDisputeRequest;
use App\Http\Resources\DisputeResource;
use App\Http\Resources\ReturnRequestResource;
use App\Models\Dispute;
use App\Models\ReturnRequest;
use App\Services\DisputeService;
use App\Services\ReturnRequestService;
use App\Support\Pagination\CursorPaginates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Knuckles\Scribe\Attributes as Doc;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Doc\Group('Pós-venda')]
final class PostSaleController extends Controller
{
    use CursorPaginates;

    public function __construct(
        private readonly DisputeService $disputeService,
        private readonly ReturnRequestService $returnRequestService,
    ) {}

    #[Doc\Subgroup('Seller')]
    #[Doc\Endpoint('Disputas da loja')]
    #[Doc\Response('{"data":[{"id":"01a1462e-6a1c-73a8-9a51-0e8c2d7f4b10","order_seller_id":"01a1461f-2b3c-7d4e-8f90-1a2b3c4d5e6f","status":"open","reason":"not_as_described","description":"A cor do produto é diferente da anunciada.","seller_response":null,"seller_responded_at":null,"resolution_notes":null,"resolved_at":null,"refund_id":null,"created_at":"2026-10-20T10:00:00-03:00"}],"links":{"first":null,"last":null,"prev":null,"next":null},"meta":{"path":"http://127.0.0.1:8080/api/v1/...","per_page":20,"next_cursor":null,"prev_cursor":null}}', status: 200)]
    public function disputes(Request $request): AnonymousResourceCollection
    {
        return DisputeResource::collection($this->cursorPaginate(
            $this->ownedBy(Dispute::query(), $request),
            $request,
            cursorColumn: 'id',
            descending: true,
        ));
    }

    #[Doc\Subgroup('Seller')]
    #[Doc\Endpoint('Responder disputa', 'Registra a versão do seller; só o admin decide.')]
    #[Doc\Response('{"data":{"id":"01a1462e-6a1c-73a8-9a51-0e8c2d7f4b10","order_seller_id":"01a1461f-2b3c-7d4e-8f90-1a2b3c4d5e6f","status":"open","reason":"not_as_described","description":"A cor do produto é diferente da anunciada.","seller_response":"Enviamos conforme o anúncio.","seller_responded_at":"2026-10-20T10:00:00-03:00","resolution_notes":null,"resolved_at":null,"refund_id":null,"created_at":"2026-10-20T10:00:00-03:00"}}', status: 200)]
    public function respondDispute(RespondDisputeRequest $request, Dispute $dispute): DisputeResource
    {
        $this->ensureSellerOwns($request, $dispute);
        $this->authorize('respond', $dispute);

        return new DisputeResource(
            $this->disputeService->respond($request->user(), $dispute, $request->validated('response')),
        );
    }

    #[Doc\Subgroup('Seller')]
    #[Doc\Endpoint('Devoluções da loja')]
    #[Doc\Response('{"data":[{"id":"01a14630-1d2e-7f40-8a5b-6c7d8e9f0a1b","order_seller_id":"01a1461f-2b3c-7d4e-8f90-1a2b3c4d5e6f","status":"requested","reason":"Não serviu, quero devolver o produto.","amount_cents":9990,"decision_notes":null,"decided_at":null,"refund_id":null,"created_at":"2026-10-20T10:00:00-03:00"}],"links":{"first":null,"last":null,"prev":null,"next":null},"meta":{"path":"http://127.0.0.1:8080/api/v1/...","per_page":20,"next_cursor":null,"prev_cursor":null}}', status: 200)]
    public function returns(Request $request): AnonymousResourceCollection
    {
        return ReturnRequestResource::collection($this->cursorPaginate(
            $this->ownedBy(ReturnRequest::query(), $request),
            $request,
            cursorColumn: 'id',
            descending: true,
        ));
    }

    #[Doc\Subgroup('Seller')]
    #[Doc\Endpoint('Aprovar devolução', 'Dispara o reembolso do valor pedido, sem repor estoque.')]
    #[Doc\Response('{"data":{"id":"01a14630-1d2e-7f40-8a5b-6c7d8e9f0a1b","order_seller_id":"01a1461f-2b3c-7d4e-8f90-1a2b3c4d5e6f","status":"approved","reason":"Não serviu, quero devolver o produto.","amount_cents":9990,"decision_notes":null,"decided_at":"2026-10-20T10:00:00-03:00","refund_id":"01a14650-4b5c-7d6e-8f90-a1b2c3d4e5f6","created_at":"2026-10-20T10:00:00-03:00"}}', status: 200)]
    public function approveReturn(ApproveReturnRequestRequest $request, ReturnRequest $returnRequest): ReturnRequestResource
    {
        $this->ensureSellerOwns($request, $returnRequest);
        $this->authorize('decide', $returnRequest);

        return new ReturnRequestResource(
            $this->returnRequestService->approve($request->user(), $returnRequest, $request->validated('notes')),
        );
    }

    #[Doc\Subgroup('Seller')]
    #[Doc\Endpoint('Recusar devolução', 'Justificativa obrigatória; o saldo deixa de ficar travado.')]
    #[Doc\Response('{"data":{"id":"01a14630-1d2e-7f40-8a5b-6c7d8e9f0a1b","order_seller_id":"01a1461f-2b3c-7d4e-8f90-1a2b3c4d5e6f","status":"rejected","reason":"Não serviu, quero devolver o produto.","amount_cents":9990,"decision_notes":"Produto foi usado.","decided_at":"2026-10-20T10:00:00-03:00","refund_id":null,"created_at":"2026-10-20T10:00:00-03:00"}}', status: 200)]
    public function rejectReturn(RejectReturnRequestRequest $request, ReturnRequest $returnRequest): ReturnRequestResource
    {
        $this->ensureSellerOwns($request, $returnRequest);
        $this->authorize('decide', $returnRequest);

        return new ReturnRequestResource(
            $this->returnRequestService->reject($request->user(), $returnRequest, $request->validated('notes')),
        );
    }

    private function ownedBy(Builder $query, Request $request): Builder
    {
        return $query->whereHas(
            'orderSeller.store',
            static fn (Builder $store): Builder => $store->where('user_id', $request->user()->id),
        );
    }

    private function ensureSellerOwns(Request $request, Dispute|ReturnRequest $model): void
    {
        $model->loadMissing('orderSeller.store:id,user_id');

        if ($model->orderSeller?->store?->user_id !== $request->user()?->id) {
            throw new NotFoundHttpException('Recurso não encontrado.');
        }
    }
}
