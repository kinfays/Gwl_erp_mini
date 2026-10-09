<?php

use App\Http\Controllers\Commercial\CommercialCustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\Api\AndroidManagementWebhookController;
use App\Http\Controllers\Assets\AssetAuditExportController;
use App\Http\Controllers\Assets\AssetModuleController;
use App\Http\Controllers\Assets\AssetSummaryExportController;
use App\Http\Controllers\Assets\MdmEnterpriseController;
use App\Http\Controllers\Assets\MdmModuleController;
use App\Http\Controllers\Blog\BlogController;
use App\Http\Controllers\Commercial\CommercialExportController;
use App\Http\Controllers\Commercial\CommercialModuleController;
use App\Http\Controllers\HealthSafety\EquipmentController;
use App\Http\Controllers\HealthSafety\ExportController;
use App\Http\Controllers\HealthSafety\HealthSafetyModuleController;
use App\Http\Controllers\HealthSafety\IncidentDocumentController;
use App\Http\Controllers\HealthSafety\LabelController;
use App\Http\Controllers\HealthSafety\PpeController;
use App\Http\Controllers\CreditUnion\CreditUnionModuleController;
use App\Http\Controllers\Leave\LeaveApprovalsController;
use App\Http\Controllers\Leave\LeaveCompulsoryController;
use App\Http\Controllers\Leave\LeaveExportController;
use App\Http\Controllers\Leave\LeaveHomeController;
use App\Http\Controllers\Leave\LeaveLetterController;
use App\Http\Controllers\Leave\LeaveHrAnalyticsController;
use App\Http\Controllers\Leave\LeaveHrContactsController;
use App\Http\Controllers\Letters\LetterRegisterExportController;
use App\Http\Middleware\EnsureCanSignLetters;
use App\Http\Controllers\Letters\LetterScanController;
use App\Http\Controllers\Letters\TransmittalSheetController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Staff\StaffController;
use App\Http\Controllers\Transport\TransportExpenseExportController;
use App\Http\Controllers\Transport\TransportModuleController;
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
        // The HR dashboard (listed under Staff Management along with the HR tools).
        Route::get('/hr-dashboard', [LeaveHomeController::class, 'hrDashboard'])
            ->middleware('role:hr_headoffice,hr_region,admin,super_admin')
            ->name('hr-dashboard');
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
        // Head Office HR and Global Admin only (super_admin passes every role check). Regional HR have no access.
        Route::get('/compulsory', [LeaveCompulsoryController::class, 'index'])
            ->middleware(['role:hr_headoffice,admin,super_admin', 'permission:leave.manage_compulsory'])
            ->name('compulsory');
        // Workforce analytics: Head Office HR, Global Admin and super_admin see every region, regional HR their own.
        Route::get('/hr-analytics', [LeaveHrAnalyticsController::class, 'index'])
            ->middleware('role:hr_headoffice,hr_region,admin,super_admin')
            ->name('hr-analytics');
        // Approval letters. Who may open each is checked again in the controller and the Livewire component.
        Route::get('/letters/{leaveRequest}', [LeaveLetterController::class, 'show'])->whereNumber('leaveRequest')->name('letters.show');
        Route::get('/letters/{leaveRequest}/pdf', [LeaveLetterController::class, 'pdf'])->whereNumber('leaveRequest')->name('letters.pdf');
        Route::get('/my-signature', [LeaveLetterController::class, 'signature'])
            ->middleware(EnsureCanSignLetters::class)
            ->name('signature');
        Route::get('/letter-settings', [LeaveLetterController::class, 'settings'])
            ->middleware(['role:super_admin,admin,hr_headoffice,hr_region', 'permission:leave.manage_letter_settings'])
            ->name('letter-settings');
        Route::get('/acting', [LeaveLetterController::class, 'acting'])
            ->middleware(['role:super_admin,admin,hr_headoffice,hr_region', 'permission:leave.manage_acting'])
            ->name('acting');
        Route::get('/hr-contacts', [LeaveHrContactsController::class, 'index'])
            ->middleware('permission:leave.manage_hr_contacts')
            ->name('hr-contacts');
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
            Route::get('/reports', [StaffController::class, 'reports'])
                ->middleware('permission:staff.view_reports')
                ->name('reports');
            Route::get('/reports/export', [StaffController::class, 'reportsExport'])
                ->middleware('permission:staff.view_reports')
                ->name('reports.export');

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
        Route::get('/transmittals', fn () => view('letters.transmittals'))->name('transmittals');
        Route::get('/transmittals/{batch}/sheet', [TransmittalSheetController::class, 'show'])
            ->whereNumber('batch')
            ->name('transmittals.sheet');

        // Scans of the hardcopy (behind gwl.letters_scans_enabled; 404 when off). Private disk: this is the only way to a file.
        Route::get('/scans/{scan}', [LetterScanController::class, 'show'])
            ->whereNumber('scan')
            ->name('scans.show');

        // The holder's own register: preview page and the Excel / PDF exports.
        Route::middleware('permission:letters.export')->group(function () {
            Route::get('/register', fn () => view('letters.register'))->name('register');
            Route::get('/register/excel', [LetterRegisterExportController::class, 'excel'])
                ->middleware('permission:letters.export')
                ->name('register.excel');
            Route::get('/register/pdf', [LetterRegisterExportController::class, 'pdf'])
                ->middleware('permission:letters.export')
                ->name('register.pdf');
        });
    });

Route::middleware(['auth', 'active', 'module:assets', 'role:super_admin,ict_team'])
    ->prefix('assets')
    ->name('assets.')
    ->group(function () {
        Route::get('/', [AssetModuleController::class, 'home'])
            ->middleware('permission:assets.view_dashboard')
            ->name('home');

        Route::get('/device/{asset}', [AssetModuleController::class, 'show'])
            ->whereNumber('asset')
            ->middleware('permission:assets.view_inventory')
            ->name('show');

        Route::get('/summary/export/excel', [AssetSummaryExportController::class, 'excel'])
            ->middleware('permission:assets.view_dashboard')
            ->name('summary.export.excel');

        Route::get('/summary/export/pdf', [AssetSummaryExportController::class, 'pdf'])
            ->middleware('permission:assets.view_dashboard')
            ->name('summary.export.pdf');

        Route::get('/summary', [AssetModuleController::class, 'summary'])
            ->middleware('permission:assets.view_dashboard')
            ->name('summary');

        Route::get('/assets', [AssetModuleController::class, 'assets'])
            ->middleware('permission:assets.view_inventory')
            ->name('assets');

        Route::get('/phones', [AssetModuleController::class, 'phones'])
            ->middleware('permission:assets.view_inventory')
            ->name('phones');

        Route::get('/network', [AssetModuleController::class, 'network'])
            ->middleware('permission:assets.view_inventory')
            ->name('network');

        Route::get('/employees/{employee}', [AssetModuleController::class, 'employee'])
            ->middleware('permission:assets.view_inventory')
            ->name('employee');

        Route::prefix('audits')->group(function () {
            Route::get('/', [AssetModuleController::class, 'audits'])
                ->middleware('permission:assets.manage_audits')
                ->name('audits');

            Route::get('/{audit}', [AssetModuleController::class, 'audit'])
                ->middleware('permission:assets.manage_audits')
                ->name('audits.show');

            Route::get('/{audit}/export/excel', [AssetAuditExportController::class, 'excel'])
                ->middleware('permission:assets.export_audits')
                ->name('audits.export.excel');

            Route::get('/{audit}/export/pdf', [AssetAuditExportController::class, 'pdf'])
                ->middleware('permission:assets.export_audits')
                ->name('audits.export.pdf');
        });

        Route::get('/maintenance', [AssetModuleController::class, 'maintenance'])
            ->middleware('permission:assets.manage_maintenance')
            ->name('maintenance');

        Route::get('/reports', [AssetModuleController::class, 'reports'])
            ->middleware('permission:assets.manage_reports')
            ->name('reports');

        Route::get('/agent', [AssetModuleController::class, 'agent'])
            ->middleware('role:super_admin')
            ->name('agent');

        Route::get('/settings/manufacturers', [AssetModuleController::class, 'settingsManufacturers'])
            ->middleware('permission:assets.manage_manufacturers')
            ->name('settings.manufacturers');

        Route::get('/settings/models', [AssetModuleController::class, 'settingsModels'])
            ->middleware('permission:assets.manage_models')
            ->name('settings.models');

        Route::get('/settings/replacement-policy', [AssetModuleController::class, 'settingsReplacementPolicy'])
            ->middleware('permission:assets.manage_replacement_policy')
            ->name('settings.replacement-policy');

        Route::get('/settings/ip-ranges', [AssetModuleController::class, 'settingsIpRanges'])
            ->middleware('permission:assets.manage_ip_ranges')
            ->name('settings.ip-ranges');
    });

// Android Enterprise (MDM) inside the Assets module. Registered only while GWL_MDM_ENABLED is on, exactly like the
// Credit Union block below. These routes live in their own group (not the group above) because `admin` may use MDM
// but is deliberately not allowed anywhere else in Assets; a nested group cannot loosen its parent's role check.
if (config('gwl.mdm_enabled')) {
    // Google Pub/Sub push webhook. Outside auth / active / module, with no session and no CSRF check (dropping the
    // `web` group removes both), rate limited, and authenticated by Google's OIDC token plus a secret ?token= —
    // not by the api_token check the agent endpoints use.
    Route::post('/webhooks/android-management', AndroidManagementWebhookController::class)
        ->withoutMiddleware('web')
        ->middleware('throttle:mdm-webhook')
        ->name('webhooks.android-management');

    Route::middleware(['auth', 'active', 'module:assets', 'role:super_admin,admin,ict_team'])
        ->prefix('assets/mdm')
        ->name('assets.mdm.')
        ->group(function () {
            Route::get('/', [MdmModuleController::class, 'devices'])
                ->middleware('permission:assets.mdm_view')
                ->name('devices');

            Route::get('/devices/{device}', [MdmModuleController::class, 'device'])
                ->middleware('permission:assets.mdm_view')
                ->whereNumber('device')
                ->name('devices.show');

            Route::get('/enroll', [MdmModuleController::class, 'enroll'])
                ->middleware('permission:assets.mdm_enroll')
                ->name('enroll');

            Route::get('/policies', [MdmModuleController::class, 'policies'])
                ->middleware('permission:assets.mdm_view')
                ->name('policies');

            Route::get('/policies/create', [MdmModuleController::class, 'policyCreate'])
                ->middleware('permission:assets.mdm_manage_policies')
                ->name('policies.create');

            Route::get('/policies/{policy}', [MdmModuleController::class, 'policyEdit'])
                ->middleware('permission:assets.mdm_manage_policies')
                ->whereNumber('policy')
                ->name('policies.edit');

            // One-time Android Enterprise bootstrap. Google redirects the admin's browser here after signup.
            Route::middleware('role:super_admin')->group(function () {
                Route::get('/enterprise/callback', [MdmEnterpriseController::class, 'callback'])->name('enterprise.callback');
                Route::post('/enterprise/create', [MdmEnterpriseController::class, 'create'])->name('enterprise.create');
            });
        });
}

Route::middleware(['auth', 'active', 'module:transport', 'role:transport_manager,driver,employee'])
    ->prefix('transport')
    ->name('transport.')
    ->group(function () {
        Route::get('/', [TransportModuleController::class, 'home'])
            ->middleware('permission:transport.view_dashboard,transport.view_own_vehicle')
            ->name('home');

        Route::get('/vehicles', [TransportModuleController::class, 'vehicles'])
            ->middleware('permission:transport.view_vehicles,transport.view_own_vehicle')
            ->name('vehicles');

        Route::get('/mileage', [TransportModuleController::class, 'mileage'])
            ->middleware('permission:transport.log_mileage,transport.view_vehicles')
            ->name('mileage');

        Route::get('/issues', [TransportModuleController::class, 'issues'])
            ->middleware('permission:transport.report_issues,transport.manage_issues')
            ->name('issues');

        Route::get('/maintenance', [TransportModuleController::class, 'maintenance'])
            ->middleware('permission:transport.manage_maintenance')
            ->name('maintenance');

        Route::get('/expenses', [TransportModuleController::class, 'expenses'])
            ->middleware('permission:transport.manage_expenses')
            ->name('expenses');

        Route::get('/expenses/export', TransportExpenseExportController::class)
            ->middleware('permission:transport.export_expenses')
            ->name('expenses.export');

        Route::get('/reports', [TransportModuleController::class, 'reports'])
            ->middleware('permission:transport.view_reports')
            ->name('reports');

        Route::get('/reports/export/pdf', [TransportModuleController::class, 'reportPdf'])
            ->middleware('permission:transport.view_reports')
            ->name('reports.export.pdf');

        Route::get('/reports/export/excel', [TransportModuleController::class, 'reportExcel'])
            ->middleware('permission:transport.view_reports')
            ->name('reports.export.excel');
    });

if (config('gwl.credit_union_module_enabled')) {
    Route::middleware(['auth', 'active', 'module:credit_union'])
        ->prefix('credit-union')
        ->name('credit-union.')
        ->group(function () {
            Route::get('/', [CreditUnionModuleController::class, 'home'])
                ->middleware('permission:credit_union.manage_members,credit_union.apply_membership')
                ->name('home');

            Route::get('/apply', [CreditUnionModuleController::class, 'apply'])
                ->middleware('permission:credit_union.apply_membership')
                ->name('apply');

            Route::post('/apply', [CreditUnionModuleController::class, 'submitApplication'])
                ->middleware('permission:credit_union.apply_membership')
                ->name('apply.store');

            Route::get('/members', [CreditUnionModuleController::class, 'members'])
                ->middleware('permission:credit_union.manage_members')
                ->name('members');

            Route::get('/members/applications', [CreditUnionModuleController::class, 'applications'])
                ->middleware('permission:credit_union.manage_members,credit_union.approve_membership')
                ->name('members.applications');

            Route::get('/members/{member}', [CreditUnionModuleController::class, 'memberShow'])
                ->middleware('permission:credit_union.manage_members')
                ->name('members.show');

            Route::get('/members/{member}/statement/pdf', [CreditUnionModuleController::class, 'memberStatementPdf'])
                ->middleware('permission:credit_union.manage_members')
                ->name('members.statement.pdf');

            Route::get('/deductions', [CreditUnionModuleController::class, 'deductions'])
                ->middleware('permission:credit_union.manage_deductions')
                ->name('deductions');

            Route::get('/deductions/template', [CreditUnionModuleController::class, 'deductionTemplate'])
                ->middleware('permission:credit_union.manage_deductions')
                ->name('deductions.template');

            Route::get('/deductions/{batch}', [CreditUnionModuleController::class, 'deductionBatchShow'])
                ->middleware('permission:credit_union.manage_deductions')
                ->name('deductions.show');

            Route::get('/loans', [CreditUnionModuleController::class, 'loans'])
                ->middleware('permission:credit_union.manage_loans,credit_union.approve_loans')
                ->name('loans');

            Route::get('/loans/{loan}', [CreditUnionModuleController::class, 'loanShow'])
                ->middleware('permission:credit_union.manage_loans,credit_union.approve_loans')
                ->name('loans.show');

            Route::get('/withdrawals', [CreditUnionModuleController::class, 'withdrawals'])
                ->middleware('permission:credit_union.manage_withdrawals,credit_union.approve_withdrawals')
                ->name('withdrawals');

            Route::get('/withdrawals/{withdrawal}', [CreditUnionModuleController::class, 'withdrawalShow'])
                ->middleware('permission:credit_union.manage_withdrawals,credit_union.approve_withdrawals')
                ->name('withdrawals.show');

            Route::get('/refunds', [CreditUnionModuleController::class, 'refunds'])
                ->middleware('permission:credit_union.manage_refunds')
                ->name('refunds');

            Route::get('/receipts', [CreditUnionModuleController::class, 'receipts'])
                ->middleware('permission:credit_union.manage_receipts')
                ->name('receipts');

            Route::get('/interest-distributions', [CreditUnionModuleController::class, 'interestDistributions'])
                ->middleware('permission:credit_union.manage_interest_distribution,credit_union.approve_interest_distribution')
                ->name('interest-distributions');

            Route::get('/interest-distributions/{distribution}', [CreditUnionModuleController::class, 'interestDistributionShow'])
                ->middleware('permission:credit_union.manage_interest_distribution,credit_union.approve_interest_distribution')
                ->name('interest-distributions.show');
        });
}

if (config('gwl.commercial_module_enabled')) {
    Route::middleware(['auth', 'active', 'module:commercial'])
        ->prefix('commercial')
        ->name('commercial.')
        ->group(function () {
            Route::get('/', [CommercialModuleController::class, 'home'])
                ->middleware('permission:commercial.view_dashboard,commercial.view_billing,commercial.view_reading,commercial.view_customer_analytics,commercial.upload_reports,commercial.resolve_matches,commercial.void_batches')
                ->name('home');

            Route::get('/export/{report}/{format}', [CommercialExportController::class, 'download'])
                ->where('report', '[a-z-]+')
                ->where('format', 'excel|pdf')
                ->middleware('permission:commercial.export_reports')
                ->name('export');

            Route::get('/settings', [CommercialModuleController::class, 'settings'])
                ->middleware('permission:commercial.manage_settings')
                ->name('settings');

            Route::get('/summary', [CommercialModuleController::class, 'summary'])
                ->middleware('permission:commercial.view_dashboard,commercial.view_billing,commercial.view_reading')
                ->name('summary');

            Route::get('/billing', [CommercialModuleController::class, 'billing'])
                ->middleware('permission:commercial.view_billing')
                ->name('billing');

            Route::get('/reading', [CommercialModuleController::class, 'reading'])
                ->middleware('permission:commercial.view_reading,commercial.view_reader_performance')
                ->name('reading');

            Route::get('/reading/readers/{staffId}', [CommercialModuleController::class, 'readerShow'])
                ->where('staffId', '[A-Za-z0-9_-]+')
                ->middleware('permission:commercial.view_reader_performance')
                ->name('reading.reader');

            Route::get('/batches', [CommercialModuleController::class, 'batches'])
                ->middleware('permission:commercial.upload_reports,commercial.resolve_matches,commercial.void_batches')
                ->name('batches');

            Route::get('/batches/{batch}', [CommercialModuleController::class, 'batchShow'])
                ->middleware('permission:commercial.upload_reports,commercial.resolve_matches,commercial.void_batches')
                ->name('batches.show');

            // Customer list (rptCustomerDetails): millions of customers, personal data. Switched on and off by its own flag; every
            // page, list and export also re-checks the viewer's region and permission (CustomerScope / the scoping trait).
            if (config('gwl.commercial_customer_list_enabled')) {
                Route::prefix('customers')->name('customers')->group(function () {
                    Route::get('/', [CommercialCustomerController::class, 'dashboard'])
                        ->middleware('permission:commercial.view_customer_analytics')
                        ->name('');

                    Route::get('/find', [CommercialCustomerController::class, 'list'])
                        ->middleware('permission:commercial.view_customer_analytics')
                        ->name('.list');

                    Route::get('/uploads', [CommercialCustomerController::class, 'uploads'])
                        ->middleware('permission:commercial.upload_reports,commercial.resolve_matches,commercial.void_batches')
                        ->name('.uploads');

                    Route::post('/uploads', [CommercialCustomerController::class, 'upload'])
                        ->middleware(['permission:commercial.upload_reports', 'throttle:20,1'])
                        ->name('.upload');

                    Route::get('/batches/{batch}', [CommercialCustomerController::class, 'batch'])
                        ->whereNumber('batch')
                        ->middleware('permission:commercial.upload_reports,commercial.resolve_matches,commercial.void_batches')
                        ->name('.batch');

                    Route::get('/settings', [CommercialCustomerController::class, 'lookups'])
                        ->middleware('permission:commercial.manage_settings')
                        ->name('.lookups');

                    Route::get('/export/{report}/{format}', [CommercialCustomerController::class, 'export'])
                        ->where('report', 'summary|list')
                        ->where('format', 'excel')
                        ->middleware(['permission:commercial.export_reports', 'permission:commercial.view_customer_analytics'])
                        ->name('.export');

                    Route::get('/downloads/{token}', [CommercialCustomerController::class, 'download'])
                        ->where('token', '[0-9a-f-]{36}')
                        ->middleware('permission:commercial.export_reports')
                        ->name('.download');

                    Route::get('/{customer}', [CommercialCustomerController::class, 'show'])
                        ->whereNumber('customer')
                        ->middleware('permission:commercial.view_customer_analytics')
                        ->name('.show');
                });
            }
        });
}

if (config('gwl.blog_module_enabled')) {
    // Regional Blog: every member of staff reads the published articles of their OWN region (module access is true for every
    // role); only blog.manage_posts (PR Officer) writes. Region scope is BlogVisibility's, re-checked in every component and
    // controller action: an article outside the user's region answers 404.
    Route::middleware(['auth', 'active', 'module:blog'])
        ->prefix('blog')
        ->name('blog.')
        ->group(function () {
            Route::get('/', [BlogController::class, 'home'])->name('home');

            Route::get('/manage', [BlogController::class, 'manage'])
                ->middleware('permission:blog.manage_posts')
                ->name('manage');

            Route::get('/manage/create', [BlogController::class, 'create'])
                ->middleware('permission:blog.manage_posts')
                ->name('create');

            Route::get('/manage/{post}/edit', [BlogController::class, 'edit'])
                ->whereNumber('post')
                ->middleware('permission:blog.manage_posts')
                ->name('edit');

            Route::get('/posts/{post}', [BlogController::class, 'show'])
                ->whereNumber('post')
                ->name('show');

            Route::get('/posts/{post}/cover', [BlogController::class, 'cover'])
                ->whereNumber('post')
                ->name('cover');
        });
}

if (config('gwl.health_safety_module_enabled')) {
    // Every member of staff may report an incident, so the module is open to all roles; the permissions below decide
    // what each one sees, and every Livewire component and controller re-checks scope (IncidentVisibility).
    Route::middleware(['auth', 'active', 'module:health_safety'])
        ->prefix('health-safety')
        ->name('health_safety.')
        ->group(function () {
            Route::get('/', [HealthSafetyModuleController::class, 'home'])
                ->middleware('permission:health_safety.report_incident,health_safety.view_incidents,health_safety.view_dashboard')
                ->name('home');

            Route::get('/report', [HealthSafetyModuleController::class, 'report'])
                ->middleware('permission:health_safety.report_incident')
                ->name('report');

            Route::get('/my-reports', [HealthSafetyModuleController::class, 'mine'])
                ->middleware('permission:health_safety.report_incident')
                ->name('mine');

            Route::get('/incidents', [HealthSafetyModuleController::class, 'incidents'])
                ->middleware('permission:health_safety.view_incidents')
                ->name('incidents');

            Route::get('/incidents/{incident}', [HealthSafetyModuleController::class, 'show'])
                ->whereNumber('incident')
                ->middleware('permission:health_safety.report_incident,health_safety.view_incidents')
                ->name('incidents.show');

            Route::get('/incidents/{incident}/print', [IncidentDocumentController::class, 'print'])
                ->whereNumber('incident')
                ->middleware('permission:health_safety.report_incident,health_safety.view_incidents')
                ->name('incidents.print');

            Route::get('/incidents/{incident}/pdf', [IncidentDocumentController::class, 'pdf'])
                ->whereNumber('incident')
                ->middleware('permission:health_safety.report_incident,health_safety.view_incidents')
                ->name('incidents.pdf');

            Route::get('/attachments/{attachment}', [IncidentDocumentController::class, 'attachment'])
                ->whereNumber('attachment')
                ->middleware('permission:health_safety.report_incident,health_safety.view_incidents')
                ->name('attachments.show');

            Route::get('/actions', [HealthSafetyModuleController::class, 'actions'])
                ->middleware('permission:health_safety.report_incident,health_safety.view_incidents')
                ->name('actions');

            Route::get('/sites', [HealthSafetyModuleController::class, 'sites'])
                ->middleware('permission:health_safety.manage_master_data')
                ->name('sites');

            // Phase 2: fire extinguishers and first aid kits. A named responsible person may open THEIR item and record a
            // check without any equipment permission, so the item pages accept report_incident (every role) and the
            // component decides; everything else is behind its own permission.
            Route::get('/extinguishers', [EquipmentController::class, 'extinguishers'])
                ->middleware('permission:health_safety.view_equipment')
                ->name('extinguishers.index');

            Route::get('/extinguishers/create', [EquipmentController::class, 'createExtinguisher'])
                ->middleware('permission:health_safety.manage_equipment')
                ->name('extinguishers.create');

            Route::get('/extinguishers/{extinguisher}', [EquipmentController::class, 'showExtinguisher'])
                ->whereNumber('extinguisher')
                ->middleware('permission:health_safety.report_incident,health_safety.view_equipment')
                ->name('extinguishers.show');

            Route::get('/extinguishers/{extinguisher}/edit', [EquipmentController::class, 'editExtinguisher'])
                ->whereNumber('extinguisher')
                ->middleware('permission:health_safety.manage_equipment')
                ->name('extinguishers.edit');

            Route::get('/kits', [EquipmentController::class, 'kits'])
                ->middleware('permission:health_safety.view_equipment')
                ->name('kits.index');

            Route::get('/kits/create', [EquipmentController::class, 'createKit'])
                ->middleware('permission:health_safety.manage_equipment')
                ->name('kits.create');

            Route::get('/kits/{kit}', [EquipmentController::class, 'showKit'])
                ->whereNumber('kit')
                ->middleware('permission:health_safety.report_incident,health_safety.view_equipment')
                ->name('kits.show');

            Route::get('/kits/{kit}/edit', [EquipmentController::class, 'editKit'])
                ->whereNumber('kit')
                ->middleware('permission:health_safety.manage_equipment')
                ->name('kits.edit');

            Route::get('/kit-templates', [EquipmentController::class, 'kitTemplates'])
                ->middleware('permission:health_safety.manage_master_data')
                ->name('kit-templates');

            Route::get('/equipment-import', [EquipmentController::class, 'import'])
                ->middleware('permission:health_safety.manage_equipment')
                ->name('equipment-import');

            Route::get('/equipment-import/template/{type}', [EquipmentController::class, 'importTemplate'])
                ->where('type', 'extinguishers|kits')
                ->middleware('permission:health_safety.manage_equipment')
                ->name('equipment-import.template');

            Route::get('/equipment-files/{service}', [EquipmentController::class, 'certificate'])
                ->whereNumber('service')
                ->middleware('permission:health_safety.view_equipment')
                ->name('equipment-files.show');

            // Phase 4: the editable settings (hs_manager and super_admin).
            Route::get('/settings', [HealthSafetyModuleController::class, 'settings'])
                ->middleware('permission:health_safety.manage_settings')
                ->name('settings');

            // Phase 4: the dated-items register (the exports are routed with the other exports below).
            Route::get('/expiry-register', [HealthSafetyModuleController::class, 'expiryRegister'])
                ->middleware('permission:health_safety.view_equipment')
                ->name('expiry-register');

            // Phase 4: exports (export_reports here; the report's own permission is checked in the controller).
            Route::get('/export/{report}', [ExportController::class, 'show'])
                ->where('report', 'incidents|actions|extinguishers|kits|ppe-stock|ppe-issues|ppe-gaps|expiry-register|expiry-register-pdf')
                ->middleware('permission:health_safety.export_reports')
                ->name('export');

            // Phase 3b: QR labels. The scan page is for anyone signed in (the controller decides what they may do and says one
            // thing for "not there" and "not yours"); the PDFs are manage_equipment, the site posters manage_master_data.
            Route::get('/scan/{type}/{id}', [LabelController::class, 'scan'])
                ->where('type', 'extinguisher|kit')
                ->whereNumber('id')
                ->name('scan');

            Route::get('/labels/extinguishers', [LabelController::class, 'extinguishers'])
                ->middleware('permission:health_safety.manage_equipment')
                ->name('labels.extinguishers');

            Route::get('/labels/kits', [LabelController::class, 'kits'])
                ->middleware('permission:health_safety.manage_equipment')
                ->name('labels.kits');

            Route::get('/sites/posters', [LabelController::class, 'posters'])
                ->middleware('permission:health_safety.manage_master_data')
                ->name('posters');

            Route::get('/my-equipment', [EquipmentController::class, 'myEquipment'])
                ->middleware('permission:health_safety.report_incident')
                ->name('my-equipment');

            // Phase 3: PPE. Viewing stock, issues and gaps is view_equipment; posting stock, issuing and closing are guarded
            // again in the components and services with manage_ppe; set-up is manage_master_data. My PPE needs no
            // permission at all (the component needs an employee record) and shows only the viewer's own issues.
            Route::get('/ppe/stock', [PpeController::class, 'stock'])
                ->middleware('permission:health_safety.view_equipment')
                ->name('ppe.stock');

            Route::get('/ppe/issues', [PpeController::class, 'issues'])
                ->middleware('permission:health_safety.view_equipment')
                ->name('ppe.issues');

            Route::get('/ppe/gaps', [PpeController::class, 'gaps'])
                ->middleware('permission:health_safety.view_equipment')
                ->name('ppe.gaps');

            Route::get('/ppe/types', [PpeController::class, 'types'])
                ->middleware('permission:health_safety.manage_master_data')
                ->name('ppe.types');

            Route::get('/ppe/entitlements', [PpeController::class, 'entitlements'])
                ->middleware('permission:health_safety.manage_master_data')
                ->name('ppe.entitlements');

            Route::get('/ppe/reorder-levels', [PpeController::class, 'reorderLevels'])
                ->middleware('permission:health_safety.manage_master_data')
                ->name('ppe.reorder-levels');

            Route::get('/ppe/import', [PpeController::class, 'import'])
                ->middleware('permission:health_safety.manage_ppe')
                ->name('ppe.import');

            Route::get('/ppe/import/template', [PpeController::class, 'importTemplate'])
                ->middleware('permission:health_safety.manage_ppe')
                ->name('ppe.import.template');

            Route::get('/my-ppe', [PpeController::class, 'mine'])
                ->name('my-ppe');
        });
}

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
