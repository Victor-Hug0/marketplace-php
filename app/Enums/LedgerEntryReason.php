<?php

declare(strict_types=1);

namespace App\Enums;

enum LedgerEntryReason: string
{
    case SALE = 'sale';
    case COMMISSION = 'commission';
    case REFUND = 'refund';
    case COMMISSION_REFUND = 'commission_refund';
    case PAYOUT = 'payout';
}
