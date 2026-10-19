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

final class PayoutController extends Controller
{
    use CursorPaginates;

    public function __construct(
        private readonly PayoutService $payoutService,
    ) {}

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
