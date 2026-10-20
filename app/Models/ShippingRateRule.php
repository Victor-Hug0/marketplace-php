<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ShippingZone;
use Database\Factories\ShippingRateRuleFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShippingRateRule extends Model
{
    /** @use HasFactory<ShippingRateRuleFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'service_code',
        'service_name',
        'zone',
        'max_weight_grams',
        'price_cents',
        'eta_days',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'zone' => ShippingZone::class,
            'max_weight_grams' => 'integer',
            'price_cents' => 'integer',
            'eta_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
