<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PlatformFeeType;
use App\Models\PlatformFeeRule;
use Illuminate\Database\Seeder;

final class PlatformFeeRuleSeeder extends Seeder
{
    public function run(): void
    {
        if (PlatformFeeRule::query()->inEffectAt(now())->exists()) {
            return;
        }

        PlatformFeeRule::query()->create([
            'name' => 'Comissão global MVP',
            'type' => PlatformFeeType::PERCENTAGE,
            'percent_bps' => (int) config('payments.commission.default_percent_bps'),
            'fixed_cents' => null,
            'starts_at' => now(),
            'ends_at' => null,
        ]);
    }
}
