<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Concerns\EnforcesModuleAccess;
use App\Http\Controllers\Controller;
use App\Models\HsExtinguisherService;
use App\Models\HsFireExtinguisher;
use App\Models\HsFirstAidKit;
use App\Models\Permission;
use App\Services\HealthSafety\EquipmentImportService;
use App\Services\HealthSafety\EquipmentScope;
use App\Services\HealthSafety\FireExtinguisherService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Thin, like the other module controllers: route middleware and the Livewire components do the real checks (module,
 * permission, scope). This one also serves the import templates and the service certificates, which have no component.
 */
class EquipmentController extends Controller
{
    use EnforcesModuleAccess;

    public function __construct(protected EquipmentScope $scope) {}

    public function extinguishers(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_HEALTH_SAFETY);

        return view('health_safety.extinguishers');
    }

    public function createExtinguisher(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_HEALTH_SAFETY);

        return view('health_safety.extinguisher-form', ['extinguisher' => null]);
    }

    public function showExtinguisher(Request $request, HsFireExtinguisher $extinguisher): View
    {
        $this->enforceModule($request, Permission::MODULE_HEALTH_SAFETY);
        abort_unless($this->scope->canView($request->user(), $extinguisher), 403, 'This extinguisher is not available to you.');

        return view('health_safety.extinguisher-show', ['extinguisher' => $extinguisher]);
    }

    public function editExtinguisher(Request $request, HsFireExtinguisher $extinguisher): View
    {
        $this->enforceModule($request, Permission::MODULE_HEALTH_SAFETY);
        abort_unless($this->scope->canManage($request->user(), $extinguisher), 403, 'You may not change this extinguisher.');

        return view('health_safety.extinguisher-form', ['extinguisher' => $extinguisher]);
    }

    public function kits(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_HEALTH_SAFETY);

        return view('health_safety.kits');
    }

    public function createKit(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_HEALTH_SAFETY);

        return view('health_safety.kit-form', ['kit' => null]);
    }

    public function showKit(Request $request, HsFirstAidKit $kit): View
    {
        $this->enforceModule($request, Permission::MODULE_HEALTH_SAFETY);
        abort_unless($this->scope->canView($request->user(), $kit), 403, 'This kit is not available to you.');

        return view('health_safety.kit-show', ['kit' => $kit]);
    }

    public function editKit(Request $request, HsFirstAidKit $kit): View
    {
        $this->enforceModule($request, Permission::MODULE_HEALTH_SAFETY);
        abort_unless($this->scope->canManage($request->user(), $kit), 403, 'You may not change this kit.');

        return view('health_safety.kit-form', ['kit' => $kit]);
    }

    public function kitTemplates(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_HEALTH_SAFETY);

        return view('health_safety.kit-templates');
    }

    public function import(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_HEALTH_SAFETY);

        return view('health_safety.equipment-import');
    }

    public function importTemplate(Request $request, EquipmentImportService $imports, string $type): BinaryFileResponse|\Symfony\Component\HttpFoundation\Response
    {
        $this->enforceModule($request, Permission::MODULE_HEALTH_SAFETY);
        abort_unless(in_array($type, [EquipmentImportService::KIND_EXTINGUISHERS, EquipmentImportService::KIND_KITS], true), 404);

        return Excel::download($imports->templateExport($type), 'health_safety_'.$type.'_template.xlsx');
    }

    public function myEquipment(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_HEALTH_SAFETY);

        return view('health_safety.my-equipment');
    }

    /**
     * A service certificate. Only people who may see the register entry (view_equipment, in their scope) can open it: the
     * named responsible person, who may record checks and nothing else, cannot.
     */
    public function certificate(Request $request, HsExtinguisherService $service, FireExtinguisherService $extinguishers): StreamedResponse
    {
        $this->enforceModule($request, Permission::MODULE_HEALTH_SAFETY);

        $service->loadMissing('extinguisher');
        $user = $request->user();

        abort_unless($this->scope->can($user, 'health_safety.view_equipment') && $this->scope->contains($user, $service->extinguisher), 403, 'This certificate is not available to you.');
        abort_unless($service->certificate_path && $extinguishers->disk()->exists($service->certificate_path), 404);

        return $extinguishers->disk()->response($service->certificate_path, $service->certificate_name ?? 'certificate', [
            'Content-Type' => $service->certificate_mime ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=0, no-store',
        ], 'inline');
    }
}
