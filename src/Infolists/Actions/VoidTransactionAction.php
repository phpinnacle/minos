<?php

namespace PHPinnacle\Minos\Infolists\Actions;

use PHPinnacle\Minos\Enums\TransactionType;

class VoidTransactionAction extends TransactionOperationAction
{
    protected static function operation(): TransactionType
    {
        return TransactionType::VOID;
    }
}
