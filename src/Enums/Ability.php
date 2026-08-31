<?php

namespace PHPinnacle\Minos\Enums;

enum Ability: string
{
    case Offline = 'offline';
    case Online = 'online';
    case Recurring = 'recurring';
}
