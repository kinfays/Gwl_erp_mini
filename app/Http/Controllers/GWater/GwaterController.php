<?php

namespace App\Http\Controllers\GWater;

use App\Http\Controllers\Concerns\EnforcesModuleAccess;
use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Services\GWater\GwaterImportService;
use App\Services\GWater\GwaterVisibility;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Thin: the route middleware (module + permission), the Livewire components and GwaterVisibility do the real checks. Which
 * books a user reads is never decided here.
 */
class GwaterController extends Controller
{
    use EnforcesModuleAccess;

    public function home(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_GWATER);

        return view('gwater.home');
    }

    public function sales(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_GWATER);

        return view('gwater.sales');
    }

    public function purchases(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_GWATER);

        return view('gwater.purchases');
    }

    public function stock(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_GWATER);

        return view('gwater.stock');
    }

    public function prices(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_GWATER);

        return view('gwater.prices');
    }

    public function opening(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_GWATER);

        return view('gwater.opening');
    }

    public function pnl(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_GWATER);

        return view('gwater.pnl');
    }

    public function settings(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_GWATER);

        return view('gwater.settings');
    }

    public function import(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_GWATER);

        return view('gwater.import');
    }

    public function importTemplate(Request $request, GwaterImportService $imports)
    {
        $this->enforceModule($request, Permission::MODULE_GWATER);
        abort_unless(app(GwaterVisibility::class)->can($request->user(), 'gwater.record'), 403);

        return Excel::download($imports->templateExport(), 'gwater-import-template.xlsx');
    }

    public function damages(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_GWATER);

        return view('gwater.damages');
    }

    public function expenses(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_GWATER);

        return view('gwater.expenses');
    }

    public function payments(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_GWATER);

        return view('gwater.payments');
    }

    public function reimbursements(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_GWATER);

        return view('gwater.reimbursements');
    }
}
