<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ShippingQuoteRequest;
use App\Http\Resources\ShippingQuoteResource;
use App\Models\Address;
use App\Services\ShippingQuoteService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ShippingQuoteController extends Controller
{
    public function __construct(
        private readonly ShippingQuoteService $shippingQuoteService,
    ) {}

    public function store(ShippingQuoteRequest $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $destinationCep = $request->filled('address_id')
            ? Address::query()
                ->where('user_id', $user->id)
                ->whereKey($request->string('address_id')->value())
                ->firstOrFail()
                ->cep
            : $request->string('cep')->value();

        return ShippingQuoteResource::collection(
            $this->shippingQuoteService->quoteCart($user, $destinationCep),
        );
    }
}
