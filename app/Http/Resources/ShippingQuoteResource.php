<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\DTOs\ShippingOption;
use App\DTOs\StoreShippingQuote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property StoreShippingQuote $resource
 */
final class ShippingQuoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $quote = $this->resource;

        return [
            'store' => [
                'id' => $quote->store->id,
                'store_name' => $quote->store->store_name,
                'slug' => $quote->store->slug,
            ],
            'zone' => $quote->zone->value,
            'destination_cep' => $quote->destinationCep,
            'billable_weight_grams' => $quote->billableWeightGrams,
            'options' => array_map(
                static fn (ShippingOption $option): array => [
                    'service_code' => $option->serviceCode,
                    'service_name' => $option->serviceName,
                    'price_cents' => $option->priceCents,
                    'eta_days' => $option->etaDays,
                ],
                $quote->options,
            ),
        ];
    }
}
