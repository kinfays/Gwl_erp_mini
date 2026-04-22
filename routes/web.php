<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UacController;
use App\Livewire\Leave\Approvals;
use App\Livewire\Leave\ApplyForLeave;
use App\Livewire\Leave\MyLeaveHistory;
use App\Livewire\Leave\ReviewRequest;
use App\Livewire\Leave\CompulsoryLeave;
use App\Livewire\Leave\TeamLeaveCalendar;
use Illuminate\Support\Facades\Route;

// Public Routes
Route::get('/', function () {
    return view('auth.login'); // Or redirect('/login') depending on your preference
});

/* 
Route::get('/', function () {
    return redirect()->route('dashboard');
}); */


// Post-Login Welcome Screen
Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'active'])
    ->name('dashboard');

// -----------------------------------------------------------------------
// UAC MODULE (Strictly Protected by Auth, Active Status, Module, and Roles)
// -----------------------------------------------------------------------
Route::middleware(['auth', 'active', 'module:uac', 'role:admin,super_admin'])
    ->prefix('uac')
    ->name('uac.')
    ->group(function () {

    // Main UAC Dashboard / Master Layout
    Route::get('/', [UacController::class, 'index'])->name('index');

    // User Management
    Route::get('/users', [UacController::class, 'users'])->name('users');
    Route::post('/users', [UacController::class, 'store'])->name('users.store');
    Route::put('/users/{user}', [UacController::class, 'updateUser'])->name('users.update');
    Route::get('/users/{user}', [UacController::class, 'show'])->name('users.show');
    Route::patch('/users/{user}/status', [UacController::class, 'toggleStatus'])->name('users.toggle-status');
    Route::post('/users/{user}/invite', [UacController::class, 'resendInvite'])->name('users.invite');
    Route::get('/employees/search', [UacController::class, 'searchEmployees'])->name('employees.search');

    // Roles & Permissions
    Route::get('/roles', [UacController::class, 'rolesPermissions'])->name('roles');
    Route::post('/roles', [UacController::class, 'storeRole'])->name('roles.store');
    Route::put('/roles/{role}/permissions', [UacController::class, 'updateRolePermissions'])->name('roles.permissions.update');

    // Bulk Import for HR Data
    Route::get('/import', [UacController::class, 'bulkImport'])->name('import');
    Route::get('/import/template/{type}', [UacController::class, 'downloadImportTemplate'])->name('import.template');
    Route::post('/import/preview', [UacController::class, 'previewImport'])->name('import.preview');
    Route::post('/import/run', [UacController::class, 'runImport'])->name('import.run');

    // -------------------------------------------------------------------
    // AUDIT LOG (Strictly Super Admin Only)
    // -------------------------------------------------------------------
    Route::middleware(['role:super_admin'])->group(function () {
        Route::get('/audit-log', [UacController::class, 'auditLog'])->name('audit-log');
    });

});

// -----------------------------------------------------------------------
// USER PROFILE
// -----------------------------------------------------------------------
Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// -----------------------------------------------------------------------
// Leave Management Module (Protected by Auth, Active Status, and Module Access)
// -----------------------------------------------------------------------
Route::middleware(['auth'])->group(function () {

    Route::get('/leave', \App\Livewire\Leave\HrDashboard::class)
        ->name('leave.dashboard');

    Route::get('/leave/requests', Approvals::class)
        ->name('leave.requests');

    Route::get('/leave/apply', ApplyForLeave::class)
        ->name('leave.apply');

    Route::get('/leave/my-history', MyLeaveHistory::class)
        ->name('leave.my-history');

    Route::get('/leave/team', TeamLeaveCalendar::class)
        ->name('leave.team');

});


Route::middleware(['auth'])->group(function () {

    Route::get('/leave/approvals', Approvals::class)
        ->name('leave.approvals');

    Route::get('/leave/requests/{leaveRequest}',
        ReviewRequest::class
    )->name('leave.review');
});

Route::middleware(['auth', 'can:leave.manage_compulsory'])
    ->get('/leave/compulsory', CompulsoryLeave::class)
    ->name('leave.compulsory');

require __DIR__.'/auth.php';
