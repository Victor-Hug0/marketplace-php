<?php

namespace App\Enums;

enum RoleName: string
{
    case BUYER = 'buyer';
    case SELLER = 'seller';
    case ADMIN = 'admin';
}
