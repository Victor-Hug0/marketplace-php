<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DisputeReason;
use App\Http\Requests\OpenDisputeRequest;
use App\Http\Requests\StoreReturnRequestRequest;
use App\Http\Resources\DisputeResource;
use App\Http\Resources\ReturnRequestResource;
use App\Models\OrderSeller;
use App\Services\DisputeService;
use App\Services\ReturnRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PostSaleController extends Controller
{
    public function __construct(
        private readonly DisputeService $disputeService,
        private readonly ReturnRequestService $returnRequestService,
    ) {}

    public function openDispute(OpenDisputeRequest $request, OrderSeller $orderSeller): JsonResponse
    {
        $this->ensureBuyerOwns($request, $orderSeller);
        $this->authorize('openPostSale', $orderSeller);

        $dispute = $this->disputeService->open(
            $request->user(),
            $orderSeller,
            DisputeReason::from($request->validated('reason')),
            $request->validated('description'),
        );

        return (new DisputeResource($dispute))
            ->additional(['message' => 'Disputa aberta com sucesso.'])
            ->response()
            ->setStatusCode(201);
    }

    public function requestReturn(StoreReturnRequestRequest $request, OrderSeller $orderSeller): JsonResponse
    {
        $this->ensureBuyerOwns($request, $orderSeller);
        $this->authorize('openPostSale', $orderSeller);

        $returnRequest = $this->returnRequestService->request(
            $request->user(),
            $orderSeller,
            $request->validated('reason'),
            $request->validated('amount_cents'),
        );

        return (new ReturnRequestResource($returnRequest))
            ->additional(['message' => 'Devolução solicitada com sucesso.'])
            ->response()
            ->setStatusCode(201);
    }

    private function ensureBuyerOwns(Request $request, OrderSeller $orderSeller): void
    {
        $orderSeller->loadMissing('order:id,buyer_id');

        if ($orderSeller->order?->buyer_id !== $request->user()?->id) {
            throw new NotFoundHttpException('Recurso não encontrado.');
        }
    }
}
