<?php

declare(strict_types=1);

namespace App\Enums;

enum LedgerAccountType: string
{
    case SELLER = 'seller';
    case PLATFORM = 'platform';
}
