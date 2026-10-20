<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OfferStatus;
use App\Enums\StoreStatus;
use App\Support\Money;
use Database\Factories\OfferFactory;
use Illuminate\Database\Eloquent\Builder;
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
        'price_cents',
        'promotional_price_cents',
        'promo_starts_at',
        'promo_ends_at',
        'stock',
        'reserved_stock',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'promotional_price_cents' => 'integer',
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

    /**
     * Offers a buyer can purchase right now: active, from an active store, with stock left.
     *
     * @param  Builder<Offer>  $query
     * @return Builder<Offer>
     */
    public function scopePurchasable(Builder $query): Builder
    {
        return $query
            ->where('offers.status', OfferStatus::ACTIVE)
            ->whereColumn('offers.stock', '>', 'offers.reserved_stock')
            ->whereHas('store', fn (Builder $store) => $store->where('status', StoreStatus::ACTIVE));
    }

    /**
     * SQL twin of currentPriceCents(); bind the reference time twice.
     */
    public static function currentPriceCentsSql(): string
    {
        return 'CASE WHEN offers.promotional_price_cents IS NOT NULL'
            .' AND (offers.promo_starts_at IS NULL OR offers.promo_starts_at <= ?)'
            .' AND (offers.promo_ends_at IS NULL OR offers.promo_ends_at >= ?)'
            .' THEN offers.promotional_price_cents ELSE offers.price_cents END';
    }

    public function availableStock(): int
    {
        return max(0, $this->stock - $this->reserved_stock);
    }

    public function currentPriceCents(): int
    {
        return $this->hasActivePromotion() ? $this->promotional_price_cents : $this->price_cents;
    }

    /**
     * Decimal BRL string ("1999.90") for API output.
     */
    public function currentPrice(): string
    {
        return Money::format($this->currentPriceCents());
    }

    public function hasActivePromotion(?Carbon $at = null): bool
    {
        if ($this->promotional_price_cents === null) {
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
