<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreAddressRequest;
use App\Http\Requests\UpdateAddressRequest;
use App\Http\Resources\AddressResource;
use App\Models\Address;
use App\Services\AddressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class AddressController extends Controller
{
    public function __construct(
        private readonly AddressService $addressService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Address::class);

        $addresses = Address::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('is_default')
            ->orderBy('created_at')
            ->get();

        return AddressResource::collection($addresses);
    }

    public function store(StoreAddressRequest $request): JsonResponse
    {
        $address = $this->addressService->create(
            $request->user(),
            $request->validated(),
        );

        return (new AddressResource($address))
            ->additional(['message' => 'Endereço criado com sucesso.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Address $address): AddressResource
    {
        $this->ensureOwner($request, $address);
        $this->authorize('view', $address);

        return new AddressResource($address);
    }

    public function update(UpdateAddressRequest $request, Address $address): AddressResource
    {
        $this->ensureOwner($request, $address);

        return new AddressResource(
            $this->addressService->update(
                $request->user(),
                $address,
                $request->validated(),
            ),
        );
    }

    public function destroy(Request $request, Address $address): JsonResponse
    {
        $this->ensureOwner($request, $address);
        $this->authorize('delete', $address);

        $this->addressService->delete($request->user(), $address);

        return response()->json(null, 204);
    }

    public function makeDefault(Request $request, Address $address): AddressResource
    {
        $this->ensureOwner($request, $address);
        $this->authorize('update', $address);

        return new AddressResource(
            $this->addressService->makeDefault($request->user(), $address),
        );
    }

    private function ensureOwner(Request $request, Address $address): void
    {
        if ($address->user_id !== $request->user()?->id) {
            throw new NotFoundHttpException('Recurso não encontrado.');
        }
    }
}
