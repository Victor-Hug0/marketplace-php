<?php

declare(strict_types=1);

namespace App\Enums;

enum LedgerEntryReason: string
{
    case SALE = 'sale';
    case COMMISSION = 'commission';
}
