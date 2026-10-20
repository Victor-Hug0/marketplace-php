<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Enums\ShippingZone;
use App\Models\ShippingRateRule;

trait ConfiguresShipping
{
    /**
     * Zero-price rate for the default service in every zone, so tests that
     * are not about freight keep order totals equal to item subtotals.
     */
    protected function useFreeShipping(): void
    {
        foreach (ShippingZone::cases() as $zone) {
            ShippingRateRule::factory()->zone($zone)->create([
                'service_code' => config('shipping.default_service_code'),
                'max_weight_grams' => 1_000_000,
                'price_cents' => 0,
            ]);
        }
    }
}
