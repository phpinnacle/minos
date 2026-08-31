<?php

namespace PHPinnacle\Minos\Models;

use Illuminate\Database\Eloquent\Model as BaseModel;

class Model extends BaseModel
{
    public function getConnectionName(): ?string
    {
        return config('phpinnacle-minos.connection', parent::getConnectionName());
    }
}
