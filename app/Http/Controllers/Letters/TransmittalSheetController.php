<?php

namespace App\Http\Controllers\Letters;

use App\Http\Controllers\Concerns\EnforcesModuleAccess;
use App\Http\Controllers\Controller;
use App\Models\LetterDispatchBatch;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;

/**
 * The printable hand-over sheet of a transmittal. It lists letters the reader may not otherwise see, so it is limited
 * to the two desks it is between (sender and recipient) and super_admin.
 */
class TransmittalSheetController extends Controller
{
    use EnforcesModuleAccess;

    public function show(Request $request, LetterDispatchBatch $batch)
    {
        $this->enforceModule($request, 'letters');

        $user = $request->user();
        $employeeId = ($user->employee ?? $user->employeeByStaffId)?->id;

        abort_unless(
            $user->hasRoles('super_admin')
                || ($employeeId !== null && in_array($employeeId, [$batch->from_secretariat_id, $batch->to_secretariat_id], true)),
            403
        );

        $batch->load([
            'fromSecretariat.department',
            'toSecretariat.department',
            'routingHistories' => fn ($hops) => $hops->orderBy('id'),
            'routingHistories.letter' => fn ($letter) => $letter
                ->with('memoSender')
                ->when(config('gwl.letters_scans_enabled'), fn ($query) => $query->withCount(['scans as active_scans_count' => fn ($scans) => $scans->whereNull('voided_at')])),
        ]);

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);

        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('letters.exports.transmittal-sheet', ['batch' => $batch])->render());
        $pdf->setPaper('a4', 'landscape');
        $pdf->render();

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$batch->batch_no.'.pdf"',
        ]);
    }
}
