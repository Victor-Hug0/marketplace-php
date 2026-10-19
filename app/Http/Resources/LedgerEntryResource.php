<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class LedgerEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $metadata = $this->metadata ?? [];

        return [
            'id' => $this->id,
            'account_type' => $this->account_type,
            'reason' => $this->reason,
            'amount_cents' => $this->amount_cents,
            'currency' => $this->currency,
            'order_seller_id' => $this->order_seller_id,
            'commission' => [
                'base_cents' => $metadata['commission_base_cents'] ?? null,
                'fee_cents' => $metadata['fee_cents'] ?? null,
                'fee_type' => $metadata['fee_type'] ?? null,
                'percent_bps' => $metadata['percent_bps'] ?? null,
                'fixed_cents' => $metadata['fixed_cents'] ?? null,
            ],
            'created_at' => $this->created_at?->timezone(config('app.timezone'))->toIso8601String(),
        ];
    }
}
