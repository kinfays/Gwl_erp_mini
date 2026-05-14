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

Route::middleware(['auth', 'active', 'module:uac', 'role:admin,super_admin,ict_team'])
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

        Route::get('/roles', [UacController::class, 'rolesPermissions'])
            ->middleware('role:admin,super_admin')
            ->name('roles');

        Route::get('/import', [ImportController::class, 'uac'])
            ->middleware('role:admin,super_admin')
            ->name('import');
        Route::get('/import/template/{type}', [ImportController::class, 'downloadTemplate'])
            ->middleware('role:admin,super_admin')
            ->defaults('context', 'uac')
            ->name('import.template');
        Route::post('/import/preview', [ImportController::class, 'preview'])
            ->middleware('role:admin,super_admin')
            ->defaults('context', 'uac')
            ->name('import.preview');
        Route::post('/import/run', [ImportController::class, 'run'])
            ->middleware('role:admin,super_admin')
            ->defaults('context', 'uac')
            ->name('import.run');
        Route::post('/import/clear', [ImportController::class, 'clear'])
            ->middleware('role:admin,super_admin')
            ->defaults('context', 'uac')
            ->name('import.clear');

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
    'role:hr_headoffice,hr_region,super_admin,manager,departmental_manager,district_manager,chief_manager,regional_chief_manager',
])->prefix('staff')
    ->name('staff.')
    ->group(function () {
        Route::get('/', [StaffController::class, 'index'])->name('index');
        Route::get('/export', [StaffController::class, 'export'])->name('export');
        Route::get('/users/{user}', [StaffController::class, 'showUser'])->name('users.show');

        Route::middleware(['role:hr_headoffice,hr_region,super_admin'])->group(function () {
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
            Route::post('/import/clear', [ImportController::class, 'clear'])
                ->defaults('context', 'staff')
                ->name('import.clear');

            Route::get('/departments', [StaffController::class, 'departments'])
                ->middleware('permission:staff.manage_departments')
                ->name('departments');
            Route::get('/regions', [StaffController::class, 'regions'])
                ->middleware('permission:staff.manage_regions')
                ->name('regions');
            Route::get('/locations', [StaffController::class, 'locations'])
                ->middleware('permission:staff.manage_locations')
                ->name('locations');
            Route::get('/job-titles', [StaffController::class, 'jobTitles'])
                ->middleware('permission:staff.manage_job_titles')
                ->name('job-titles');
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

require __DIR__.'/auth.php';
