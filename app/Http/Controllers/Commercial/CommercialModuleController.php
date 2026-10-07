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
