<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReturnRequestStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnRequest extends Model
{
    use HasUuids;

    protected $fillable = [
        'order_seller_id',
        'requested_by',
        'status',
        'reason',
        'amount_cents',
        'decision_notes',
        'decided_by',
        'decided_at',
        'refund_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReturnRequestStatus::class,
            'amount_cents' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    public function orderSeller(): BelongsTo
    {
        return $this->belongsTo(OrderSeller::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    public function isPending(): bool
    {
        return $this->status === ReturnRequestStatus::REQUESTED;
    }
}
