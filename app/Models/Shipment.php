<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ShipmentStatus;
use Database\Factories\ShipmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Shipment extends Model
{
    /** @use HasFactory<ShipmentFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'order_seller_id',
        'tracking_code',
        'carrier',
        'service_code',
        'status',
        'shipped_at',
        'estimated_delivery_at',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ShipmentStatus::class,
            'shipped_at' => 'datetime',
            'estimated_delivery_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function orderSeller(): BelongsTo
    {
        return $this->belongsTo(OrderSeller::class);
    }
}
