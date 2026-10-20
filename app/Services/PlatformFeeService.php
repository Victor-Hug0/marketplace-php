<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\CommissionBreakdown;
use App\Enums\PlatformFeeType;
use App\Models\PlatformFeeRule;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final class PlatformFeeService
{
    public function ruleInEffect(?CarbonInterface $moment = null): ?PlatformFeeRule
    {
        return PlatformFeeRule::query()
            ->inEffectAt($moment ?? now())
            ->orderByDesc('starts_at')
            ->first();
    }

    /**
     * Fee never exceeds the commission base; percentages round half up to the cent.
     */
    public function calculate(int $baseCents, ?CarbonInterface $moment = null): CommissionBreakdown
    {
        if ($baseCents < 0) {
            throw new InvalidArgumentException('A base de comissão não pode ser negativa.');
        }

        $rule = $this->ruleInEffect($moment);

        if ($rule === null) {
            $bps = (int) config('payments.commission.default_percent_bps');

            return $this->percentage($baseCents, $bps, null);
        }

        return match ($rule->type) {
            PlatformFeeType::PERCENTAGE => $this->percentage($baseCents, (int) $rule->percent_bps, $rule->id),
            PlatformFeeType::FIXED => new CommissionBreakdown(
                baseCents: $baseCents,
                feeCents: min((int) $rule->fixed_cents, $baseCents),
                type: PlatformFeeType::FIXED,
                percentBps: null,
                fixedCents: (int) $rule->fixed_cents,
                ruleId: $rule->id,
            ),
        };
    }

    private function percentage(int $baseCents, int $bps, ?string $ruleId): CommissionBreakdown
    {
        if ($bps < 0 || $bps > 10000) {
            throw new InvalidArgumentException('A comissão percentual deve estar entre 0 e 10000 bps.');
        }

        return new CommissionBreakdown(
            baseCents: $baseCents,
            feeCents: intdiv($baseCents * $bps + 5000, 10000),
            type: PlatformFeeType::PERCENTAGE,
            percentBps: $bps,
            fixedCents: null,
            ruleId: $ruleId,
        );
    }
}
