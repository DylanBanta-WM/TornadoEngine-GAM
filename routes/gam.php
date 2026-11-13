<?php

use Illuminate\Support\Facades\Route;
use TornadoEngine\Gam\Http\GamController;

Route::middleware(['web', 'auth', 'can:administration'])
    ->prefix('administration/gam')
    ->name('gam.')
    ->group(function () {
        Route::post('/config', [GamController::class, 'config'])->name('config');
        Route::post('/check', [GamController::class, 'check'])->name('check');
        Route::post('/remove', [GamController::class, 'remove'])->name('remove');
    });
