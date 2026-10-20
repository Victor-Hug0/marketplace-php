<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderSellerStatus;
use Database\Factories\OrderSellerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class OrderSeller extends Model
{
    /** @use HasFactory<OrderSellerFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'order_id',
        'store_id',
        'status',
        'subtotal_cents',
        'shipping_cents',
        'total_cents',
        'shipping_service_code',
        'shipping_service_name',
        'shipping_eta_days',
        'shipping_quoted_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderSellerStatus::class,
            'subtotal_cents' => 'integer',
            'shipping_cents' => 'integer',
            'total_cents' => 'integer',
            'shipping_eta_days' => 'integer',
            'shipping_quoted_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class)->latest();
    }

    public function shipment(): HasOne
    {
        return $this->hasOne(Shipment::class);
    }

    public function statusHistories(): MorphMany
    {
        return $this->morphMany(OrderStatusHistory::class, 'statusable')->latest();
    }
}
