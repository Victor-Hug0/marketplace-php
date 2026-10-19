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

final class PayoutController extends Controller
{
    use CursorPaginates;

    public function __construct(
        private readonly PayoutService $payoutService,
    ) {}

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

    public function markPaid(Request $request, Payout $payout): PayoutResource
    {
        $this->authorize('process', $payout);

        return new PayoutResource(
            $this->payoutService->markPaid($request->user(), $payout),
        );
    }

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
