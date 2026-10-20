<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ShippingQuoteRequest;
use App\Http\Resources\ShippingQuoteResource;
use App\Models\Address;
use App\Services\ShippingQuoteService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Knuckles\Scribe\Attributes as Doc;

#[Doc\Group('Frete')]
final class ShippingQuoteController extends Controller
{
    public function __construct(
        private readonly ShippingQuoteService $shippingQuoteService,
    ) {}

    #[Doc\Endpoint('Cotar frete do carrinho', 'Uma cotação por loja do carrinho, para um `cep` ou `address_id` do próprio usuário. Opções da mais barata para a mais cara; modalidade que não comporta o peso não aparece.')]
    #[Doc\Response('{"data":[{"store":{"id":"01a14513-0e23-726f-b299-104a96511ebb","store_name":"Loja Demo","slug":"loja-demo"},"zone":"local","destination_cep":"01311000","billable_weight_grams":500,"options":[{"service_code":"simulated_standard","service_name":"Entrega padrão (simulada)","price_cents":1290,"eta_days":3},{"service_code":"simulated_express","service_name":"Entrega expressa (simulada)","price_cents":2190,"eta_days":1}]}]}', status: 200)]
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
