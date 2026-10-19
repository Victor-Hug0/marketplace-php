<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlatformFeeType;
use Carbon\CarbonInterface;
use Database\Factories\PlatformFeeRuleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlatformFeeRule extends Model
{
    /** @use HasFactory<PlatformFeeRuleFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'type',
        'percent_bps',
        'fixed_cents',
        'starts_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => PlatformFeeType::class,
            'percent_bps' => 'integer',
            'fixed_cents' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<PlatformFeeRule>  $query
     * @return Builder<PlatformFeeRule>
     */
    public function scopeInEffectAt(Builder $query, CarbonInterface $moment): Builder
    {
        return $query
            ->where('starts_at', '<=', $moment)
            ->where(static fn (Builder $query): Builder => $query
                ->whereNull('ends_at')
                ->orWhere('ends_at', '>', $moment));
    }
}
