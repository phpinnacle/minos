<?php

namespace PHPinnacle\Minos\Enums;

enum Decision: string
{
    case Success = 'success';
    case Failure = 'failure';
    case Pending = 'pending';
    case Cancel = 'cancel';
}
