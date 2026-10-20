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

final class PostSaleController extends Controller
{
    use CursorPaginates;

    public function __construct(
        private readonly DisputeService $disputeService,
        private readonly ReturnRequestService $returnRequestService,
    ) {}

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

    public function approveReturn(ApproveReturnRequestRequest $request, ReturnRequest $returnRequest): ReturnRequestResource
    {
        $this->authorize('decide', $returnRequest);

        return new ReturnRequestResource(
            $this->returnRequestService->approve($request->user(), $returnRequest, $request->validated('notes')),
        );
    }

    public function rejectReturn(RejectReturnRequestRequest $request, ReturnRequest $returnRequest): ReturnRequestResource
    {
        $this->authorize('decide', $returnRequest);

        return new ReturnRequestResource(
            $this->returnRequestService->reject($request->user(), $returnRequest, $request->validated('notes')),
        );
    }
}
