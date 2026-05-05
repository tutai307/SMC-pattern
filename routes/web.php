<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;

Route::middleware('throttle:30,1')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/academy', [DashboardController::class, 'academy']);
    Route::get('/planner', [DashboardController::class, 'planner']);
});

Route::middleware('throttle:60,1')->group(function () {
    Route::delete('/signals/{id}', [DashboardController::class, 'deleteSignal'])->name('signals.delete');
    Route::post('/signals/bulk-delete', [DashboardController::class, 'bulkDelete'])->name('signals.bulkDelete');
    Route::post('/clear-all-signals', [DashboardController::class, 'clearAllSignals'])->name('signals.clearAll');
    Route::post('/signals/{id}/fill', [DashboardController::class, 'fillSignal'])->name('signals.fill');
    Route::post('/signals/{id}/reset', [DashboardController::class, 'resetSignal'])->name('signals.reset');
});

Route::post('/advisor', [DashboardController::class, 'advisor'])->middleware('throttle:20,1')->name('advisor');

Route::get('/health', fn() => response()->json([
    'status' => 'ok',
    'time'   => now()->toIso8601String(),
    'db'     => DB::connection()->getPdo() ? 'ok' : 'error',
]))->name('health');
