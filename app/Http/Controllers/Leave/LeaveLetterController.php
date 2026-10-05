<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Concerns\EnforcesModuleAccess;
use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Services\Leave\LeaveActingAssignmentService;
use App\Services\Leave\LeaveLetterService;
use App\Services\Leave\LeaveLetterSettingsService;
use App\Services\Leave\SignatureService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;

/**
 * Leave approval letters: the letter screen, the PDF, My Signature, Letter Settings and Acting Assignments. Each page
 * checks who may open it again here, over the route middleware and the Livewire component.
 */
class LeaveLetterController extends Controller
{
    use EnforcesModuleAccess;

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $this->enforceModule($request, 'leave');

            return $next($request);
        });
    }

    public function show(Request $request, LeaveRequest $leaveRequest, LeaveLetterService $letters)
    {
        abort_unless($letters->canView($request->user(), $leaveRequest), 403);

        return view('leave.letter', ['leaveRequest' => $leaveRequest]);
    }

    /**
     * The letter as a PDF. Opening it counts as a print (it locks the letter's editable fields and is audited). It carries the
     * signature, so it is never cached and never reachable by a URL anyone else could guess or reuse.
     */
    public function pdf(Request $request, LeaveRequest $leaveRequest, LeaveLetterService $letters)
    {
        $user = $request->user();

        abort_unless($letters->canView($user, $leaveRequest), 403);

        $letter = $leaveRequest->letter()->firstOrFail();

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);

        $pdf = new Dompdf($options);
        $pdf->loadHtml($letters->html($letter));
        $pdf->setPaper('a4', 'portrait');
        $pdf->render();
        $output = $pdf->output();

        $letters->recordPrint($letter, $user);

        $name = 'leave-approval-letter-'.$leaveRequest->id.'.pdf';

        return response($output, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="'.$name.'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function signature(Request $request, SignatureService $signatures)
    {
        abort_unless($signatures->canSign($request->user()), 403);

        return view('leave.signature');
    }

    public function settings(Request $request, LeaveLetterSettingsService $settings)
    {
        abort_unless($settings->canAccess($request->user()), 403);

        return view('leave.letter-settings');
    }

    public function acting(Request $request, LeaveActingAssignmentService $acting)
    {
        abort_unless($acting->canManage($request->user()), 403);

        return view('leave.acting');
    }
}
