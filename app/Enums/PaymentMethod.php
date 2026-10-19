<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentMethod: string
{
    case PIX = 'pix';
    case CREDIT_CARD = 'credit_card';
    case BOLETO = 'boleto';
}
