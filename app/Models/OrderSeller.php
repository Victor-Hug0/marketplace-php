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
use Illuminate\Database\Eloquent\Relations\MorphMany;

class OrderSeller extends Model
{
    /** @use HasFactory<OrderSellerFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'order_id',
        'store_id',
        'status',
        'subtotal',
        'shipping_total',
        'total',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderSellerStatus::class,
            'subtotal' => 'decimal:2',
            'shipping_total' => 'decimal:2',
            'total' => 'decimal:2',
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

    public function statusHistories(): MorphMany
    {
        return $this->morphMany(OrderStatusHistory::class, 'statusable');
    }
}
