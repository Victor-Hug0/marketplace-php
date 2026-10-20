<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * @mixin DatabaseNotification
 */
final class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->data['title'] ?? null,
            'message' => $this->data['message'] ?? null,
            'action_path' => $this->data['action_path'] ?? null,
            'data' => $this->data['data'] ?? [],
            'read_at' => $this->read_at?->timezone(config('app.timezone'))->toIso8601String(),
            'created_at' => $this->created_at?->timezone(config('app.timezone'))->toIso8601String(),
        ];
    }
}
