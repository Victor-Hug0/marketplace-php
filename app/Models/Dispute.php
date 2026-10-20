<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DisputeReason;
use App\Enums\DisputeStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Dispute extends Model
{
    use HasUuids;

    protected $fillable = [
        'order_seller_id',
        'opened_by',
        'status',
        'reason',
        'description',
        'seller_response',
        'seller_responded_at',
        'resolution_notes',
        'resolved_by',
        'resolved_at',
        'refund_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => DisputeStatus::class,
            'reason' => DisputeReason::class,
            'seller_responded_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function orderSeller(): BelongsTo
    {
        return $this->belongsTo(OrderSeller::class);
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    public function isOpen(): bool
    {
        return $this->status === DisputeStatus::OPEN;
    }
}
