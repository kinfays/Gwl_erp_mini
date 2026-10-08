<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Concerns\EnforcesModuleAccess;
use App\Http\Controllers\Controller;
use App\Models\HsFireExtinguisher;
use App\Models\HsFirstAidKit;
use App\Models\Permission;
use App\Services\HealthSafety\EquipmentLabelService;
use App\Services\HealthSafety\EquipmentScope;
use App\Services\HealthSafety\LabelBatch;
use App\Services\HealthSafety\SitePosterService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * QR labels, site posters and the page a scanned code opens. The PDFs are ordinary authorised downloads (never a public
 * URL): permission and scope are checked here and again in the services. The scan page decides what a person may do with
 * the item and otherwise says one and the same thing for "no such item" and "not yours", so existence is not revealed.
 */
class LabelController extends Controller
{
    use EnforcesModuleAccess;

    public function __construct(protected EquipmentScope $scope, protected LabelBatch $batches) {}

    public function extinguishers(Request $request, EquipmentLabelService $labels): Response
    {
        return $this->labelPdf($request, $labels, EquipmentLabelService::TYPE_EXTINGUISHER);
    }

    public function kits(Request $request, EquipmentLabelService $labels): Response
    {
        return $this->labelPdf($request, $labels, EquipmentLabelService::TYPE_KIT);
    }

    public function posters(Request $request, SitePosterService $posters): Response
    {
        $this->enforceModule($request, Permission::MODULE_HEALTH_SAFETY);
        abort_unless($this->scope->can($request->user(), 'health_safety.manage_master_data'), 403, 'You may not print site posters.');

        [$ids] = $this->selection($request);

        try {
            $result = $posters->print($request->user(), $ids);
        } catch (ValidationException $exception) {
            abort(422, collect($exception->errors())->flatten()->first());
        }

        return $this->pdf($result);
    }

    /** What a scanned code opens. */
    public function scan(Request $request, string $type, int $id): mixed
    {
        $this->enforceModule($request, Permission::MODULE_HEALTH_SAFETY);

        $user = $request->user();
        $item = match ($type) {
            EquipmentLabelService::TYPE_EXTINGUISHER => HsFireExtinguisher::query()->find($id),
            EquipmentLabelService::TYPE_KIT => HsFirstAidKit::query()->find($id),
            default => null,
        };

        // Absent, or outside the person's reach: the very same page and status.
        if (! $item || ! $this->scope->canView($user, $item)) {
            return response()->view('health_safety.scan-unavailable', [], 403);
        }

        $route = $type === EquipmentLabelService::TYPE_KIT ? 'health_safety.kits.show' : 'health_safety.extinguishers.show';
        $parameters = [$type === EquipmentLabelService::TYPE_KIT ? 'kit' : 'extinguisher' => $item->id];

        // Straight into the check form for whoever may record one (a decommissioned unit takes no more checks).
        if ($item->status !== 'decommissioned' && $this->scope->canCheck($user, $item)) {
            $parameters['check'] = 1;
        }

        return redirect()->route($route, $parameters);
    }

    protected function labelPdf(Request $request, EquipmentLabelService $labels, string $type): Response
    {
        $this->enforceModule($request, Permission::MODULE_HEALTH_SAFETY);
        abort_unless($this->scope->can($request->user(), 'health_safety.manage_equipment'), 403, 'You may not print labels.');

        [$ids, $layout] = $this->selection($request);

        try {
            $result = $labels->print($request->user(), $type, $ids, $layout);
        } catch (ValidationException $exception) {
            abort(422, collect($exception->errors())->flatten()->first());
        }

        return $this->pdf($result);
    }

    /** @return array{0: list<int>, 1: string} a one-use batch from a list screen, or a single item by id */
    protected function selection(Request $request): array
    {
        if ($request->filled('batch')) {
            $batch = $this->batches->take($request->user(), (string) $request->query('batch'));
            abort_if($batch === null, 410, 'That print request has expired. Go back and choose the items again.');

            return [$batch['ids'], $batch['layout']];
        }

        return [$request->filled('id') ? [(int) $request->query('id')] : [], (string) $request->query('layout', EquipmentLabelService::LAYOUT_STANDARD)];
    }

    /** @param  array{pdf: string, filename: string}  $result */
    protected function pdf(array $result): Response
    {
        return response($result['pdf'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$result['filename'].'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
