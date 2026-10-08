<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Concerns\EnforcesModuleAccess;
use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Services\HealthSafety\PpeImportService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thin, like the other module controllers: route middleware and the Livewire components do the real checks (module,
 * permission, scope). My PPE has no permission of its own: the component needs the viewer to have an employee record.
 */
class PpeController extends Controller
{
    use EnforcesModuleAccess;

    public function stock(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_HEALTH_SAFETY);

        return view('health_safety.ppe-stock');
    }

    public function issues(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_HEALTH_SAFETY);

        return view('health_safety.ppe-issues');
    }

    public function gaps(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_HEALTH_SAFETY);

        return view('health_safety.ppe-gaps');
    }

    public function types(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_HEALTH_SAFETY);

        return view('health_safety.ppe-types');
    }

    public function entitlements(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_HEALTH_SAFETY);

        return view('health_safety.ppe-entitlements');
    }

    public function reorderLevels(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_HEALTH_SAFETY);

        return view('health_safety.ppe-reorder-levels');
    }

    public function mine(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_HEALTH_SAFETY);

        return view('health_safety.my-ppe');
    }

    public function import(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_HEALTH_SAFETY);

        return view('health_safety.ppe-import');
    }

    public function importTemplate(Request $request, PpeImportService $imports): Response
    {
        $this->enforceModule($request, Permission::MODULE_HEALTH_SAFETY);

        return Excel::download($imports->templateExport(), 'health_safety_ppe_already_held_template.xlsx');
    }
}
