<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class ShippingOption
{
    public function __construct(
        public string $serviceCode,
        public string $serviceName,
        public int $priceCents,
        public int $etaDays,
    ) {}
}
