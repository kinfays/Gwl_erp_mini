<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UacController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'active'])
    ->name('dashboard');

Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/uac', [UacController::class, 'index'])
        ->middleware(['role:super_admin,admin', 'module:uac'])
        ->name('uac.index');
    Route::get('/uac/users', [UacController::class, 'users'])
        ->middleware(['role:super_admin,admin', 'module:uac'])
        ->name('uac.users');
    Route::get('/uac/roles-permissions', [UacController::class, 'rolesPermissions'])
        ->middleware(['role:super_admin,admin', 'module:uac'])
        ->name('uac.roles-permissions');
    Route::get('/uac/bulk-import', [UacController::class, 'bulkImport'])
        ->middleware(['role:super_admin,admin', 'module:uac'])
        ->name('uac.bulk-import');
    Route::get('/uac/audit-log', [UacController::class, 'auditLog'])
        ->middleware(['role:super_admin,admin', 'module:uac'])
        ->name('uac.audit-log');
});

require __DIR__.'/auth.php';