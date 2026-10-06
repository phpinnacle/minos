<?php

use Illuminate\Support\Facades\Route;
use PHPinnacle\Minos\Http\Controllers\NotifyController;

Route::post('/api/minos/transactions/{id}/notify', NotifyController::class)
    ->name('minos.transaction.notify');
