<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'buyer_id',
        'status',
        'currency',
        'subtotal_cents',
        'shipping_cents',
        'total_cents',
        'idempotency_key',
        'address_id',
        'shipping_recipient_name',
        'shipping_phone',
        'shipping_cep',
        'shipping_street',
        'shipping_number',
        'shipping_complement',
        'shipping_district',
        'shipping_city',
        'shipping_state',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'subtotal_cents' => 'integer',
            'shipping_cents' => 'integer',
            'total_cents' => 'integer',
        ];
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    public function sellers(): HasMany
    {
        return $this->hasMany(OrderSeller::class);
    }

    public function items(): HasManyThrough
    {
        return $this->hasManyThrough(OrderItem::class, OrderSeller::class);
    }

    public function statusHistories(): MorphMany
    {
        return $this->morphMany(OrderStatusHistory::class, 'statusable')->latest();
    }

    /**
     * @return array{
     *     recipient_name: string,
     *     phone: string,
     *     cep: string,
     *     street: string,
     *     number: string,
     *     complement: string|null,
     *     district: string,
     *     city: string,
     *     state: string
     * }
     */
    public function shippingAddressSnapshot(): array
    {
        return [
            'recipient_name' => $this->shipping_recipient_name,
            'phone' => $this->shipping_phone,
            'cep' => $this->shipping_cep,
            'street' => $this->shipping_street,
            'number' => $this->shipping_number,
            'complement' => $this->shipping_complement,
            'district' => $this->shipping_district,
            'city' => $this->shipping_city,
            'state' => $this->shipping_state,
        ];
    }

    public static function snapshotFromAddress(Address $address): array
    {
        return [
            'address_id' => $address->id,
            'shipping_recipient_name' => $address->recipient_name,
            'shipping_phone' => $address->phone,
            'shipping_cep' => $address->cep,
            'shipping_street' => $address->street,
            'shipping_number' => $address->number,
            'shipping_complement' => $address->complement,
            'shipping_district' => $address->district,
            'shipping_city' => $address->city,
            'shipping_state' => $address->state,
        ];
    }
}
