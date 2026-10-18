<?php

namespace App\Http\Controllers\GWater;

use App\Http\Controllers\Concerns\EnforcesModuleAccess;
use App\Http\Controllers\Controller;
use App\Models\GwPurchase;
use App\Models\Permission;
use App\Services\GWater\GwaterWorkflowService;
use App\Services\GWater\GwaterImportService;
use App\Services\GWater\GwaterVisibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    /** The picture of a trip's paper waybill. 404 for a trip outside the user's location (same answer as a missing one). */
    public function waybill(Request $request, int $purchase): StreamedResponse
    {
        $this->enforceModule($request, Permission::MODULE_GWATER);

        $trip = app(GwaterVisibility::class)->find($request->user(), GwPurchase::class, $purchase);
        $disk = Storage::disk(GwaterWorkflowService::WAYBILL_DISK);

        abort_unless($trip->hasWaybillPhoto() && $disk->exists($trip->waybill_photo_path), 404);

        return $disk->response($trip->waybill_photo_path, 'waybill-'.$trip->waybill_no.'.'.pathinfo($trip->waybill_photo_path, PATHINFO_EXTENSION), [
            'Content-Type' => $trip->waybill_photo_mime ?: 'image/jpeg',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ], 'inline');
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
