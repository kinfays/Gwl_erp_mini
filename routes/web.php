<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\Leave\LeaveApprovalsController;
use App\Http\Controllers\Leave\LeaveExportController;
use App\Http\Controllers\Leave\LeaveHomeController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Staff\StaffController;
use App\Http\Controllers\UacController;
use App\Http\Controllers\Visitors\VisitorExportController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('auth.login');
});

Route::get('/kiosk', fn () => view('visitors.kiosk'))->name('visitors.kiosk');

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'active'])
    ->name('dashboard');

Route::middleware(['auth', 'active', 'module:uac', 'role:admin,super_admin'])
    ->prefix('uac')
    ->name('uac.')
    ->group(function () {
        Route::get('/', [UacController::class, 'index'])->name('index');

        Route::get('/users', [UacController::class, 'users'])->name('users');
        Route::post('/users', [UacController::class, 'store'])->name('users.store');
        Route::patch('/users/{user}', [UacController::class, 'update'])->name('users.update');
        Route::get('/users/{user}', [UacController::class, 'show'])->name('users.show');
        Route::patch('/users/{user}/status', [UacController::class, 'toggleStatus'])->name('users.toggle-status');
        Route::post('/users/{user}/invite', [UacController::class, 'resendInvite'])->name('users.invite');
        Route::get('/employees/search', [UacController::class, 'searchEmployees'])->name('employees.search');

        Route::get('/roles', [UacController::class, 'rolesPermissions'])->name('roles');

        Route::get('/import', [ImportController::class, 'uac'])->name('import');
        Route::get('/import/template/{type}', [ImportController::class, 'downloadTemplate'])
            ->defaults('context', 'uac')
            ->name('import.template');
        Route::post('/import/preview', [ImportController::class, 'preview'])
            ->defaults('context', 'uac')
            ->name('import.preview');
        Route::post('/import/run', [ImportController::class, 'run'])
            ->defaults('context', 'uac')
            ->name('import.run');

        Route::middleware(['role:super_admin'])->group(function () {
            Route::get('/audit-log', [UacController::class, 'auditLog'])->name('audit-log');
        });
    });

Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware(['auth', 'active', 'module:leave'])
    ->prefix('leave')
    ->name('leave.')
    ->group(function () {
        Route::get('/', [LeaveHomeController::class, 'index'])->name('home');
        Route::get('/requests', fn () => view('leave.requests'))->name('requests');
        Route::get('/my-history', fn () => view('leave.my-history'))->name('my-history');
        Route::get('/apply', fn () => view('leave.apply'))->name('apply');
        Route::get('/approvals', [LeaveApprovalsController::class, 'index'])->name('approvals');
        Route::get('/team-dashboard', fn () => view('leave.team-dashboard'))->name('team-dashboard');
        Route::get('/reports', fn () => view('leave.reports'))
            ->middleware('permission:leave.export')
            ->name('reports');

        Route::get('/export/approved/excel', [LeaveExportController::class, 'approvedExcel'])
            ->middleware('permission:leave.export')
            ->name('export.approved.excel');
        Route::get('/export/team/excel', [LeaveExportController::class, 'teamExcel'])
            ->middleware('permission:leave.export')
            ->name('export.team.excel');
        Route::get('/compulsory', fn () => view('leave.compulsory'))
            ->middleware('permission:leave.manage_compulsory')
            ->name('compulsory');
    });

Route::middleware([
    'auth',
    'active',
    'module:staff',
    'role:hr_headoffice,hr_region,admin,super_admin,manager,departmental_manager,district_manager,chief_manager,regional_chief_manager',
])->prefix('staff')
    ->name('staff.')
    ->group(function () {
        Route::get('/', [StaffController::class, 'index'])->name('index');
        Route::get('/export', [StaffController::class, 'export'])->name('export');

        Route::middleware(['role:hr_headoffice,hr_region,admin,super_admin'])->group(function () {
            Route::get('/create', [StaffController::class, 'create'])->name('create');
            Route::get('/{employee}/edit', [StaffController::class, 'edit'])->name('edit');
            Route::patch('/{employee}/status', [StaffController::class, 'toggleStatus'])->name('toggle-status');

            Route::get('/import', [ImportController::class, 'staff'])->name('import');
            Route::get('/import/template/{type}', [ImportController::class, 'downloadTemplate'])
                ->defaults('context', 'staff')
                ->name('import.template');
            Route::post('/import/preview', [ImportController::class, 'preview'])
                ->defaults('context', 'staff')
                ->name('import.preview');
            Route::post('/import/run', [ImportController::class, 'run'])
                ->defaults('context', 'staff')
                ->name('import.run');

            Route::get('/departments', [StaffController::class, 'departments'])->name('departments');
        });
    });

Route::middleware(['auth', 'active', 'module:letters'])
    ->prefix('letters')
    ->name('letters.')
    ->group(function () {
        Route::get('/', fn () => view('letters.home'))->name('home');
        Route::get('/active', fn () => view('letters.active'))->name('active');
        Route::get('/new', fn () => view('letters.create'))
            ->middleware('permission:letters.create')
            ->name('create');
        Route::get('/closed', fn () => view('letters.active', ['closed' => true]))->name('closed');
    });

Route::middleware(['auth', 'active', 'module:visitors', 'role:receptionist'])
    ->prefix('visitors')
    ->name('visitors.')
    ->group(function () {
        Route::get('/', fn () => view('visitors.home'))->name('home');
        Route::get('/history', fn () => view('visitors.history'))->name('history');
        Route::get('/export/excel', [VisitorExportController::class, 'excel'])
            ->middleware('permission:visitors.export')
            ->name('export.excel');
        Route::get('/export/pdf', [VisitorExportController::class, 'pdf'])
            ->middleware('permission:visitors.export')
            ->name('export.pdf');
    });

require __DIR__ . '/auth.php';
