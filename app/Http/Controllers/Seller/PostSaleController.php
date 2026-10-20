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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PostSaleController extends Controller
{
    use CursorPaginates;

    public function __construct(
        private readonly DisputeService $disputeService,
        private readonly ReturnRequestService $returnRequestService,
    ) {}

    public function disputes(Request $request): AnonymousResourceCollection
    {
        return DisputeResource::collection($this->cursorPaginate(
            $this->ownedBy(Dispute::query(), $request),
            $request,
            cursorColumn: 'id',
            descending: true,
        ));
    }

    public function respondDispute(RespondDisputeRequest $request, Dispute $dispute): DisputeResource
    {
        $this->ensureSellerOwns($request, $dispute);
        $this->authorize('respond', $dispute);

        return new DisputeResource(
            $this->disputeService->respond($request->user(), $dispute, $request->validated('response')),
        );
    }

    public function returns(Request $request): AnonymousResourceCollection
    {
        return ReturnRequestResource::collection($this->cursorPaginate(
            $this->ownedBy(ReturnRequest::query(), $request),
            $request,
            cursorColumn: 'id',
            descending: true,
        ));
    }

    public function approveReturn(ApproveReturnRequestRequest $request, ReturnRequest $returnRequest): ReturnRequestResource
    {
        $this->ensureSellerOwns($request, $returnRequest);
        $this->authorize('decide', $returnRequest);

        return new ReturnRequestResource(
            $this->returnRequestService->approve($request->user(), $returnRequest, $request->validated('notes')),
        );
    }

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
