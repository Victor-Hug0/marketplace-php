<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\ShippingZone;
use App\Models\Store;

final readonly class StoreShippingQuote
{
    /**
     * @param  list<ShippingOption>  $options  cheapest first
     */
    public function __construct(
        public Store $store,
        public ShippingZone $zone,
        public string $originCep,
        public string $destinationCep,
        public int $billableWeightGrams,
        public array $options,
    ) {}

    public function option(string $serviceCode): ?ShippingOption
    {
        foreach ($this->options as $option) {
            if ($option->serviceCode === $serviceCode) {
                return $option;
            }
        }

        return null;
    }
}
