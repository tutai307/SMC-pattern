<?php

use App\Http\Controllers\MT5DataController;
use App\Http\Controllers\Api\AutoTradeController;
use Illuminate\Support\Facades\Route;

// ── Auto Trader — xác thực qua X-Auto-Trade-Token header ────────────────────
Route::prefix('auto-trade')
    ->middleware(\App\Http\Middleware\AutoTradeAuth::class)
    ->group(function () {
        Route::get('signals',               [AutoTradeController::class, 'pendingSignals']);
        Route::post('signals/{id}/executed', [AutoTradeController::class, 'markExecuted']);
        Route::post('signals/{id}/closed',   [AutoTradeController::class, 'markClosed']);
    });

// MT5 EA data bridge — không cần auth middleware (secret check di trong controller)
Route::prefix('mt5')->group(function () {
    Route::post('klines',       [MT5DataController::class, 'receiveKlines']);
    Route::post('bulk-klines',  [MT5DataController::class, 'receiveBulkKlines']);
    Route::post('tick',         [MT5DataController::class, 'receiveTick']);
    Route::get('status',        [MT5DataController::class, 'status']);
    Route::get('scan',          [MT5DataController::class, 'scanNow']);
    Route::get('bulk-export',   [MT5DataController::class, 'bulkExport']);
    Route::get('ping-telegram', [MT5DataController::class, 'pingTelegram']);
});
