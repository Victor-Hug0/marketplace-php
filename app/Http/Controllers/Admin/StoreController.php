<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStatusReasonRequest;
use App\Http\Resources\StoreResource;
use App\Models\Store;
use App\Services\StoreService;
use App\Support\Pagination\CursorPaginates;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class StoreController extends Controller
{
    use CursorPaginates;

    public function __construct(
        private readonly StoreService $storeService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Store::class);

        $stores = $this->cursorPaginate(
            Store::query()->with('owner'),
            $request,
        );

        return StoreResource::collection($stores);
    }

    public function approve(Request $request, Store $store): StoreResource
    {
        $this->authorize('review', $store);

        return new StoreResource(
            $this->storeService->approve($store, $request->user()),
        );
    }

    public function reject(
        StoreStatusReasonRequest $request,
        Store $store,
    ): StoreResource {
        $this->authorize('review', $store);

        return new StoreResource(
            $this->storeService->reject(
                $store,
                $request->user(),
                $request->string('reason')->value(),
            ),
        );
    }

    public function suspend(
        StoreStatusReasonRequest $request,
        Store $store,
    ): StoreResource {
        $this->authorize('review', $store);

        return new StoreResource(
            $this->storeService->suspend(
                $store,
                $request->user(),
                $request->string('reason')->value(),
            ),
        );
    }
}
