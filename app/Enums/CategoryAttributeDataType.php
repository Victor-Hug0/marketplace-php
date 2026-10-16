<?php

declare(strict_types=1);

namespace App\Enums;

enum CategoryAttributeDataType: string
{
    case STRING = 'string';
    case INTEGER = 'integer';
    case DECIMAL = 'decimal';
    case BOOLEAN = 'boolean';
    case ENUM = 'enum';
}
