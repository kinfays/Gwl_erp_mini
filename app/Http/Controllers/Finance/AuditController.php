<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Concerns\EnforcesModuleAccess;
use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Services\Finance\FinanceScope;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The Audit module's pages. Thin, like FinanceController; reach is FinanceScope's (as Audit: never a draft). */
class AuditController extends Controller
{
    use EnforcesModuleAccess;

    public function home(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_AUDIT);

        return view('audit.home');
    }

    public function show(Request $request, int $pv): View
    {
        $this->enforceModule($request, Permission::MODULE_AUDIT);

        return view('audit.show', ['pv' => $pv]);
    }

    /** Categories and types are the Finance manager's, per location. Audit has no screen for them: always 403. */
    public function categories(Request $request): never
    {
        $this->enforceModule($request, Permission::MODULE_AUDIT);

        abort(403, 'Categories and types are managed from Finance.');
    }

    public function reports(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_AUDIT);

        return view('audit.reports');
    }

    public function reminderSettings(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_AUDIT);

        return view('audit.reminder-settings');
    }

    public function suppliers(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_AUDIT);

        return view('audit.suppliers');
    }

    public function attachment(Request $request, int $pv, int $attachment): StreamedResponse
    {
        $this->enforceModule($request, Permission::MODULE_AUDIT);

        return app(FinanceController::class)->serve($request, $pv, $attachment, FinanceScope::AS_AUDIT);
    }
}
