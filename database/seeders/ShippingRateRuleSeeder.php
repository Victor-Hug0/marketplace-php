<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ShippingZone;
use App\Models\ShippingRateRule;
use Illuminate\Database\Seeder;

final class ShippingRateRuleSeeder extends Seeder
{
    /**
     * Price (cents) and ETA (days) per weight tier, by service and zone.
     *
     * @var array<string, array{name: string, zones: array<string, array{eta: int, tiers: array<int, int>}>}>
     */
    private const TABLE = [
        'simulated_standard' => [
            'name' => 'Entrega padrão (simulada)',
            'zones' => [
                'local' => ['eta' => 3, 'tiers' => [1000 => 1290, 5000 => 1990, 30000 => 3490]],
                'regional' => ['eta' => 5, 'tiers' => [1000 => 1890, 5000 => 2790, 30000 => 4990]],
                'national' => ['eta' => 9, 'tiers' => [1000 => 2590, 5000 => 3890, 30000 => 6990]],
            ],
        ],
        'simulated_express' => [
            'name' => 'Entrega expressa (simulada)',
            'zones' => [
                'local' => ['eta' => 1, 'tiers' => [1000 => 2190, 5000 => 3290, 30000 => 5990]],
                'regional' => ['eta' => 2, 'tiers' => [1000 => 2990, 5000 => 4490, 30000 => 7990]],
                'national' => ['eta' => 4, 'tiers' => [1000 => 3990, 5000 => 5990, 30000 => 10990]],
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::TABLE as $serviceCode => $service) {
            foreach ($service['zones'] as $zone => $config) {
                foreach ($config['tiers'] as $maxWeightGrams => $priceCents) {
                    ShippingRateRule::query()->updateOrCreate(
                        [
                            'service_code' => $serviceCode,
                            'zone' => ShippingZone::from($zone),
                            'max_weight_grams' => $maxWeightGrams,
                        ],
                        [
                            'service_name' => $service['name'],
                            'price_cents' => $priceCents,
                            'eta_days' => $config['eta'],
                            'is_active' => true,
                        ],
                    );
                }
            }
        }
    }
}
