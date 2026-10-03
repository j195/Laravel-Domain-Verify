<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DomainCheckController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/tools/blacklist', [DomainCheckController::class, 'blacklist'])->name('tools.blacklist');
    Route::get('/tools/provider', [DomainCheckController::class, 'provider'])->name('tools.provider');

    Route::post('/checks/single', [DomainCheckController::class, 'single'])->name('checks.single');
    Route::post('/checks/bulk', [DomainCheckController::class, 'bulk'])->name('checks.bulk');
    Route::get('/batches/{batch}', [DomainCheckController::class, 'show'])->name('batches.show');
    Route::post('/batches/{batch}/tick', [DomainCheckController::class, 'tick'])->name('batches.tick');
    Route::post('/batches/{batch}/handoff', [DomainCheckController::class, 'handoff'])->name('batches.handoff');
    Route::get('/batches/{batch}/export', [DomainCheckController::class, 'export'])->name('batches.export');
});
