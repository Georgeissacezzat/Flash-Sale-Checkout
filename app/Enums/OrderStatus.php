<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PrePayment = 'pre_payment';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
    case Failed = 'failed'; 

    public function isFinal(): bool
    {
        return in_array($this, [self::Paid, self::Cancelled, self::Failed]);
    }
}
