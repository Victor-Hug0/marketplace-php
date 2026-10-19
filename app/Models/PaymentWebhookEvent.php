<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentWebhookEventType;
use App\Enums\PaymentWebhookResult;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentWebhookEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'provider',
        'event_id',
        'type',
        'payment_id',
        'payload',
        'processed_at',
        'result',
    ];

    protected function casts(): array
    {
        return [
            'type' => PaymentWebhookEventType::class,
            'result' => PaymentWebhookResult::class,
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }
}
