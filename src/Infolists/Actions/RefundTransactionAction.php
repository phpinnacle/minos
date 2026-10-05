<?php

namespace PHPinnacle\Minos\Infolists\Actions;

use PHPinnacle\Minos\Enums\TransactionType;

class RefundTransactionAction extends TransactionOperationAction
{
    protected static function operation(): TransactionType
    {
        return TransactionType::REFUND;
    }
}
