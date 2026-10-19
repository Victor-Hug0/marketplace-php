<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LedgerAccountType;
use App\Enums\LedgerEntryReason;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class LedgerEntry extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'account_type',
        'store_id',
        'order_seller_id',
        'payment_id',
        'platform_fee_rule_id',
        'reason',
        'amount_cents',
        'currency',
        'idempotency_key',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'account_type' => LedgerAccountType::class,
            'reason' => LedgerEntryReason::class,
            'amount_cents' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Lançamentos do ledger são imutáveis.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Lançamentos do ledger são imutáveis.');
        });
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function orderSeller(): BelongsTo
    {
        return $this->belongsTo(OrderSeller::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function feeRule(): BelongsTo
    {
        return $this->belongsTo(PlatformFeeRule::class, 'platform_fee_rule_id');
    }
}
