<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\SyncRunController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/sync-runs');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::resource('sync-runs', SyncRunController::class)->only(['index', 'show', 'store']);
    Route::get('/sync-runs/{syncRun}/log', [SyncRunController::class, 'log'])->name('sync-runs.log');
});

if (app()->isLocal()) {
    Route::get('/xdebug-info', function () {
        if (function_exists('xdebug_info')) {
            xdebug_info();
        } else {
            echo 'Xdebug is not enabled.';
        }
    });
}
