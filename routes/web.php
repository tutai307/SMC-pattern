<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\DashboardController;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
Route::delete('/signals/{id}', [DashboardController::class, 'deleteSignal'])->name('signals.delete');
Route::post('/signals/bulk-delete', [DashboardController::class, 'bulkDelete'])->name('signals.bulkDelete');
Route::post('/clear-all-signals', [DashboardController::class, 'clearAllSignals'])->name('signals.clearAll');
Route::get('/academy', [DashboardController::class, 'academy']);
Route::get('/planner', [DashboardController::class, 'planner']);
