<?php

declare(strict_types=1);

namespace App\Enums;

enum ShippingZone: string
{
    case LOCAL = 'local';
    case REGIONAL = 'regional';
    case NATIONAL = 'national';

    /**
     * Brazilian CEPs are hierarchical: the first digit is the postal region
     * and the first three digits narrow it down to a sub-region.
     */
    public static function between(string $originCep, string $destinationCep): self
    {
        if (substr($originCep, 0, 3) === substr($destinationCep, 0, 3)) {
            return self::LOCAL;
        }

        if ($originCep[0] === $destinationCep[0]) {
            return self::REGIONAL;
        }

        return self::NATIONAL;
    }
}
