<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UacController;
use App\Http\Controllers\Leave\LeaveRequestController;
use App\Http\Controllers\Leave\LeaveApprovalsController;
use App\Http\Controllers\Leave\LeaveHomeController;
use  app\Http\Controllers\Leave\LeaveExportController;
use App\Livewire\Leave\Approvals;
use App\Livewire\Leave\ReviewRequest;
use App\Livewire\Leave\CompulsoryLeave;
use App\Livewire\Leave\HrDashboard;
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


Route::middleware(['auth', 'active', 'module:leave'])
    ->prefix('leave')
    ->name('leave.')
    ->group(function () {

        // HR Dashboard
        Route::get('/', [LeaveHomeController::class, 'index'])->name('home');
        
       // Route::get('/', [HrDashboard::class, 'leave.dashboard'])->name('home');
        

        // All requests
        //Route::get('/requests', [LeaveRequestController::class, 'index'])->name('requests.index');

        Route::get('/requests', fn () => view('leave.requests'))->name('requests');
        Route::get('/my-history', fn () => view('leave.my-history'))->name('my-history');

        // Apply for leave
        Route::get('/apply', fn () => view('leave.apply'))->name('apply');
        
        // Approval of leave
        Route::get('/approvals', [LeaveApprovalsController::class, 'index'])->name('approvals');

        //team views
        Route::get('/team-dashboard', fn () => view('leave.team-dashboard'))->middleware(['module:leave'])->name('leave.team-dashboard');

        // Exports (permission-gated)
        Route::get('/export/approved/excel', [LeaveExportController::class, 'approvedExcel'])->middleware('permission:leave.export')->name('leave.export.approved.excel');

        Route::get('/export/team/excel', [LeaveExportController::class, 'teamExcel'])->middleware('permission:leave.export')->name('leave.export.team.excel');

        // Compulsory leave (permission-gated)
        Route::get('/compulsory', fn () => view('leave.compulsory'))->middleware('permission:leave.manage_compulsory')->name('leave.compulsory');
    });



require __DIR__.'/auth.php';