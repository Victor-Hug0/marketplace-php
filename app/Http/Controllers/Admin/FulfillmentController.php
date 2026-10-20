<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderSellerResource;
use App\Models\OrderSeller;
use App\Services\FulfillmentService;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes as Doc;

#[Doc\Group('Envio')]
final class FulfillmentController extends Controller
{
    public function __construct(
        private readonly FulfillmentService $fulfillmentService,
    ) {}

    #[Doc\Subgroup('Admin')]
    #[Doc\Endpoint('Confirmar entrega (admin)', '`shipped → delivered`.')]
    #[Doc\ResponseFromApiResource(OrderSellerResource::class, OrderSeller::class, with: ['shipment'])]
    public function markDelivered(Request $request, OrderSeller $orderSeller): OrderSellerResource
    {
        $this->authorize('markDelivered', $orderSeller);

        return new OrderSellerResource(
            $this->fulfillmentService->markDelivered($request->user(), $orderSeller),
        );
    }
}
