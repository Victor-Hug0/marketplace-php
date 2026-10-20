<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\PlatformFeeType;

final readonly class CommissionBreakdown
{
    public function __construct(
        public int $baseCents,
        public int $feeCents,
        public PlatformFeeType $type,
        public ?int $percentBps,
        public ?int $fixedCents,
        public ?string $ruleId,
    ) {}

    /**
     * @return array<string, int|string|null>
     */
    public function toMetadata(): array
    {
        return [
            'commission_base_cents' => $this->baseCents,
            'fee_cents' => $this->feeCents,
            'fee_type' => $this->type->value,
            'percent_bps' => $this->percentBps,
            'fixed_cents' => $this->fixedCents,
            'rule_source' => $this->ruleId === null ? 'config' : 'rule',
        ];
    }
}
