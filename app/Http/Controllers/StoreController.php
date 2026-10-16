<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CreateStoreRequest;
use App\Http\Resources\StoreResource;
use App\Models\Store;
use App\Services\StoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StoreController extends Controller
{
    public function __construct(
        private readonly StoreService $storeService,
    ) {}

    public function store(CreateStoreRequest $request): JsonResponse
    {
        $store = $this->storeService->requestStore(
            $request->user(),
            $request->validated(),
        );

        return (new StoreResource($store))
            ->additional(['message' => 'Solicitação de loja criada com sucesso.'])
            ->response()
            ->setStatusCode(201);
    }

    public function me(Request $request): StoreResource
    {
        $store = Store::query()
            ->whereBelongsTo($request->user(), 'owner')
            ->firstOrFail();

        $this->authorize('view', $store);

        return new StoreResource($store);
    }
}
