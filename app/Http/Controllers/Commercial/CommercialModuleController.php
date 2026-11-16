<?php

namespace App\Http\Controllers\Commercial;

use App\Http\Controllers\Concerns\EnforcesModuleAccess;
use App\Http\Controllers\Controller;
use App\Models\CommercialImportBatch;
use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Thin: route middleware and the Livewire components do the real access checks (module, permission, region).
 */
class CommercialModuleController extends Controller
{
    use EnforcesModuleAccess;

    public function home(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_COMMERCIAL);

        return view('commercial.home');
    }

    public function reading(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_COMMERCIAL);

        return view('commercial.reading');
    }

    /** Phase 8c: the reading report by route and CCA. */
    public function readingRoutes(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_COMMERCIAL);

        return view('commercial.reading-routes');
    }

    /** Phase 8d: the detailed billing analyses (B1 to B13). */
    public function billingDetails(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_COMMERCIAL);

        return view('commercial.billing-details');
    }

    /** Phase 8e: the index of the analyses that need more than one file. */
    public function cross(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_COMMERCIAL);

        return view('commercial.cross');
    }

    /** Phase 8e: one of X1 and X3 to X12. */
    public function crossShow(Request $request, string $analysis): View
    {
        $this->enforceModule($request, Permission::MODULE_COMMERCIAL);
        abort_unless(array_key_exists($analysis, \App\Services\Commercial\Cross\CrossFileReport::ANALYSES), 404);

        return view('commercial.cross-show', ['analysis' => $analysis]);
    }

    /** Phase 8f: the trends screen opens on T2. */
    public function trends(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_COMMERCIAL);

        return view('commercial.trends', ['trend' => 't2']);
    }

    /** Phase 8f: one of T2 to T8. */
    public function trendsShow(Request $request, string $trend): View
    {
        $this->enforceModule($request, Permission::MODULE_COMMERCIAL);
        abort_unless(array_key_exists($trend, \App\Services\Commercial\Trends\TrendReport::TRENDS), 404);

        return view('commercial.trends', ['trend' => $trend]);
    }

    /** Phase 8e: route 360 (X2), the page behind every route link. A route outside the viewer's reach is a 404 (the Livewire page decides). */
    public function crossRoute(Request $request, int $route): View
    {
        $this->enforceModule($request, Permission::MODULE_COMMERCIAL);

        return view('commercial.cross-route', ['routeId' => $route]);
    }

    public function settings(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_COMMERCIAL);

        return view('commercial.settings');
    }

    public function summary(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_COMMERCIAL);

        return view('commercial.summary');
    }

    public function billing(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_COMMERCIAL);

        return view('commercial.billing');
    }

    public function kpis(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_COMMERCIAL);

        return view('commercial.kpis');
    }

    public function kpiTargets(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_COMMERCIAL);

        return view('commercial.kpi-targets');
    }

    public function routes(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_COMMERCIAL);

        return view('commercial.routes');
    }

    public function readerShow(Request $request, string $staffId): View
    {
        $this->enforceModule($request, Permission::MODULE_COMMERCIAL);

        return view('commercial.reader', ['staffId' => $staffId]);
    }

    public function batches(Request $request): View
    {
        $this->enforceModule($request, Permission::MODULE_COMMERCIAL);

        return view('commercial.batches');
    }

    public function batchShow(Request $request, CommercialImportBatch $batch): View
    {
        $this->enforceModule($request, Permission::MODULE_COMMERCIAL);

        return view('commercial.batch-show', ['batch' => $batch]);
    }
}
