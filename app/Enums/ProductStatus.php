<?php

declare(strict_types=1);

namespace App\Enums;

enum ProductStatus: string
{
    case DRAFT = 'draft';
    case ACTIVE = 'active';
    case PAUSED = 'paused';
    case REJECTED = 'rejected';
}
