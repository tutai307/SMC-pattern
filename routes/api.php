<?php

use App\Http\Controllers\MT5DataController;
use Illuminate\Support\Facades\Route;

// MT5 EA data bridge — không cần auth middleware (secret check di trong controller)
Route::prefix('mt5')->group(function () {
    Route::post('klines', [MT5DataController::class, 'receiveKlines']);
    Route::post('tick',   [MT5DataController::class, 'receiveTick']);
    Route::get('status',  [MT5DataController::class, 'status']);
    Route::get('scan',         [MT5DataController::class, 'scanNow']);
    Route::get('ping-telegram', [MT5DataController::class, 'pingTelegram']);
});
