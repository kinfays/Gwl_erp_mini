<?php

namespace App\Http\Controllers\Commercial;

use App\Http\Controllers\Concerns\EnforcesModuleAccess;
use App\Http\Controllers\Controller;
use App\Jobs\Commercial\ProcessBillingDetailsBatch;
use App\Models\CommercialImportBatch;
use App\Models\Permission;
use App\Services\Commercial\BillingDetails\BillingDetailsImportService;
use App\Services\Commercial\CommercialImportException;
use App\Services\Commercial\CommercialScope;
use Illuminate\Http\Request;

/**
 * The upload form of the Detailed Billing Report (rptBillingDetails_ExP): a plain POST, like the customer list's, because the workbook is far
 * bigger than a Livewire temporary upload may be. It stores the file, creates a QUEUED batch and hands it to the queue; the batch page shows
 * the progress. Nothing is read here beyond the top of the file.
 */
class CommercialBillingDetailsController extends Controller
{
    use EnforcesModuleAccess;

    public function upload(Request $request, BillingDetailsImportService $imports)
    {
        $this->enforceModule($request, Permission::MODULE_COMMERCIAL);

        $user = $request->user();
        $scope = CommercialScope::for($user);

        if ($scope->regionRestriction() === 0) {
            return back()->with('error', 'Your account has no region assigned, so you cannot upload reports. Contact an administrator.');
        }

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx', 'max:'.((int) config('gwl.commercial_billing_details_import_max_mb', 250) * 1024)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'review' => ['nullable', 'boolean'],
        ]);

        $file = $request->file('file');

        try {
            ['batch' => $batch, 'duplicate' => $duplicate] = $imports->register(
                $file->getRealPath(), $file->getClientOriginalName(), $user, $request->input('notes'), $request->boolean('review'), $scope->regionRestriction(), $scope->districtIds()
            );
        } catch (CommercialImportException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        if ($duplicate) {
            return redirect()->route('commercial.batches.show', $batch)->with('status', 'This exact file was already uploaded (batch #'.$batch->id.', '.strtolower(CommercialImportBatch::statusLabel($batch->status)).'). Nothing was done twice.');
        }

        ProcessBillingDetailsBatch::dispatch($batch->id);

        return redirect()->route('commercial.batches.show', $batch)->with('status', $batch->review
            ? 'File received. It is being read in the background; you will be asked to check what it holds before anything is loaded.'
            : 'File received. It is being read in the background; this page shows its progress.');
    }
}
