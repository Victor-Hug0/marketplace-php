<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StoreStatus;
use Database\Factories\StoreFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Store extends Model
{
    /** @use HasFactory<StoreFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * `is_platform` stays out on purpose: only PlatformStoreSeeder may set it.
     */
    protected $fillable = [
        'user_id',
        'store_name',
        'slug',
        'logo_path',
        'banner_path',
        'commercial_email',
        'commercial_phone',
        'origin_cep',
        'description',
        'document',
        'document_type',
        'status',
        'rejection_reason',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => StoreStatus::class,
            'is_platform' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<Store>  $query
     * @return Builder<Store>
     */
    public function scopePlatform(Builder $query): Builder
    {
        return $query->where('is_platform', true);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    public function orderSellers(): HasMany
    {
        return $this->hasMany(OrderSeller::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }

    public function productReviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    public function isActive(): bool
    {
        return $this->status === StoreStatus::ACTIVE;
    }

    public function isPlatform(): bool
    {
        return $this->is_platform === true;
    }
}
