<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ShipmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tracking_code' => $this->tracking_code,
            'carrier' => $this->carrier,
            'service_code' => $this->service_code,
            'status' => $this->status,
            'shipped_at' => $this->shipped_at?->timezone(config('app.timezone'))->toIso8601String(),
            'estimated_delivery_at' => $this->estimated_delivery_at?->timezone(config('app.timezone'))->toIso8601String(),
            'delivered_at' => $this->delivered_at?->timezone(config('app.timezone'))->toIso8601String(),
        ];
    }
}
