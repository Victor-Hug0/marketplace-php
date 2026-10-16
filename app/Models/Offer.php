<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OfferStatus;
use Database\Factories\OfferFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Offer extends Model
{
    /** @use HasFactory<OfferFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'product_id',
        'store_id',
        'price',
        'promotional_price',
        'promo_starts_at',
        'promo_ends_at',
        'stock',
        'reserved_stock',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'promotional_price' => 'decimal:2',
            'promo_starts_at' => 'datetime',
            'promo_ends_at' => 'datetime',
            'stock' => 'integer',
            'reserved_stock' => 'integer',
            'status' => OfferStatus::class,
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(InventoryReservation::class);
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function availableStock(): int
    {
        return max(0, $this->stock - $this->reserved_stock);
    }

    public function currentPrice(): string
    {
        if ($this->hasActivePromotion()) {
            return (string) $this->promotional_price;
        }

        return (string) $this->price;
    }

    public function hasActivePromotion(?Carbon $at = null): bool
    {
        if ($this->promotional_price === null) {
            return false;
        }

        $at ??= now();

        if ($this->promo_starts_at !== null && $at->lt($this->promo_starts_at)) {
            return false;
        }

        if ($this->promo_ends_at !== null && $at->gt($this->promo_ends_at)) {
            return false;
        }

        return true;
    }

    public function isActive(): bool
    {
        return $this->status === OfferStatus::ACTIVE;
    }
}
