<?php

use App\Http\Controllers\AkeneoAttributeController;
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

    Route::get('/attributes', [AkeneoAttributeController::class, 'index'])->name('attributes.index');
    Route::post('/attributes/sync', [AkeneoAttributeController::class, 'sync'])->name('attributes.sync');
    Route::post('/attributes/definitions', [AkeneoAttributeController::class, 'storeMissingDefinitions'])->name('attributes.definitions.store');
    Route::get('/attributes/{attribute:code}', [AkeneoAttributeController::class, 'show'])->name('attributes.show');
    Route::put('/attributes/{attribute:code}', [AkeneoAttributeController::class, 'update'])->name('attributes.update');
    Route::post('/attributes/{attribute:code}/definition', [AkeneoAttributeController::class, 'storeDefinition'])->name('attributes.definition.store');
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
