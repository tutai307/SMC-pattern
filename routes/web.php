<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AccessController;

// ── Auth (không cần đăng nhập) ───────────────────────────────────────────────
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login/send', [AuthController::class, 'sendOtp'])->name('login.send')->middleware('throttle:5,1');
Route::post('/login/verify', [AuthController::class, 'verifyOtp'])->name('login.verify')->middleware('throttle:10,1');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
Route::post('/access/request', [AccessController::class, 'submitRequest'])->name('access.request')->middleware('throttle:5,1');

Route::get('/health', fn() => response()->json([
    'status' => 'ok',
    'time'   => now()->toIso8601String(),
    'db'     => DB::connection()->getPdo() ? 'ok' : 'error',
]))->name('health');

// ── Protected (yêu cầu OTP session) ─────────────────────────────────────────
Route::middleware(\App\Http\Middleware\RequireAuth::class)->group(function () {

    Route::get('/analysis.json', [DashboardController::class, 'analysisJson'])->middleware('throttle:60,1')->name('analysis.json');
    Route::get('/price.json', [DashboardController::class, 'priceJson'])->middleware('throttle:120,1')->name('price.json');

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

    // Admin: access management
    Route::get('/admin/access', [AccessController::class, 'dashboard'])->name('admin.access');
    Route::post('/admin/access/{id}/approve', [AccessController::class, 'approve'])->name('access.approve');
    Route::post('/admin/access/{id}/deny', [AccessController::class, 'deny'])->name('access.deny');
    Route::post('/admin/access/{id}/revoke', [AccessController::class, 'revoke'])->name('access.revoke');
});
