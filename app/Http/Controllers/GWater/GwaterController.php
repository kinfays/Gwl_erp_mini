<?php

namespace App\Http\Controllers\GWater;

use App\Http\Controllers\Concerns\EnforcesModuleAccess;
use App\Http\Controllers\Controller;
use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\View\View;

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

    public function reimbursements(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_GWATER);

        return view('gwater.reimbursements');
    }
}
