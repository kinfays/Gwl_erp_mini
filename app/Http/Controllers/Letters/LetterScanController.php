<?php

namespace App\Http\Controllers\Letters;

use App\Http\Controllers\Concerns\EnforcesModuleAccess;
use App\Http\Controllers\Controller;
use App\Models\LetterScan;
use App\Services\Letters\LetterScanService;
use App\Services\Letters\LetterWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * The only way a scan is ever served. Scans sit on a private disk with no public URL; a scan is resolved through the
 * letters the viewer can see (so a letter's scan does not exist for anyone else), and is withheld until the viewer has
 * confirmed the hardcopy unless gwl.letters_scan_preview_before_confirm is on.
 */
class LetterScanController extends Controller
{
    use EnforcesModuleAccess;

    public function show(Request $request, LetterScan $scan, LetterScanService $scans, LetterWorkflowService $workflow)
    {
        abort_unless($scans->enabled(), 404);

        $this->enforceModule($request, 'letters');

        $user = $request->user();
        $employee = $user->employee ?? $user->employeeByStaffId;
        abort_if(! $employee, 403, 'Your user account is not linked to an employee record.');

        // A voided scan is hidden for everyone, and a scan of a letter you cannot see does not exist for you.
        abort_if($scan->isVoided(), 404);
        $letter = $workflow->visibleLettersQuery($employee)->find($scan->letter_id);
        abort_if(! $letter, 404);

        if (! $scans->previewBeforeConfirm() && $workflow->pendingIncomingRoute($letter, $employee)) {
            abort(403, 'Confirm receipt of the hardcopy before opening its scan.');
        }

        // Never serve from a public disk, whatever the row says.
        abort_if($scan->disk === 'public' || config("filesystems.disks.{$scan->disk}.visibility") === 'public', 404);

        $disk = Storage::disk($scan->disk);
        abort_unless($disk->exists($scan->path), 404);

        return $disk->response($scan->path, $scan->original_name, [
            'Content-Type' => $scan->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], 'inline');
    }
}
