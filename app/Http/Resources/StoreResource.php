<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

final class StoreResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_name' => $this->store_name,
            'slug' => $this->slug,
            'description' => $this->description,
            'logo_url' => $this->logo_path
                ? Storage::url($this->logo_path)
                : null,
            'banner_url' => $this->banner_path
                ? Storage::url($this->banner_path)
                : null,
            'commercial_email' => $this->commercial_email,
            'commercial_phone' => $this->commercial_phone,
            'origin_cep' => $this->origin_cep,
            'document_type' => $this->document_type,
            'document' => $this->document,
            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'reviewed_at' => $this->reviewed_at?->timezone(config('app.timezone'))->toIso8601String(),
            'owner' => UserResource::make($this->whenLoaded('owner')),
            'created_at' => $this->created_at?->timezone(config('app.timezone'))->toIso8601String(),
            'updated_at' => $this->updated_at?->timezone(config('app.timezone'))->toIso8601String(),
        ];
    }
}
