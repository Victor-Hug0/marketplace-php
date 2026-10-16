<?php

declare(strict_types=1);

namespace App\Enums;

enum ProductStatus: string
{
    case DRAFT = 'draft';
    case PENDING_MODERATION = 'pending_moderation';
    case ACTIVE = 'active';
    case REJECTED = 'rejected';
    case PAUSED = 'paused';
}
