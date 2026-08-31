<?php

namespace PHPinnacle\Minos\Enums;

enum AdjustmentType: string
{
    case Discount = 'discount';
    case Shipping = 'shipping';
    case Rounding = 'rounding';
    case Tax = 'tax';
    case Fee = 'fee';
}
