<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Concerns\EnforcesModuleAccess;
use App\Http\Controllers\Controller;
use App\Models\HsIncident;
use App\Models\HsIncidentAttachment;
use App\Models\Permission;
use App\Services\HealthSafety\IncidentVisibility;
use App\Services\HealthSafety\IncidentWorkflowService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The single-incident print copy (the Microsoft Form promised "you can print a copy of your answer"), its PDF, and the
 * photos. All three are built from IncidentVisibility::present() or checked against IncidentVisibility::canView(), so
 * they carry only what that viewer may see on screen, and photos are never reachable by a public URL.
 */
class IncidentDocumentController extends Controller
{
    use EnforcesModuleAccess;

    public function __construct(
        protected IncidentVisibility $visibility,
        protected IncidentWorkflowService $workflow,
    ) {}

    public function print(Request $request, HsIncident $incident): Response
    {
        return response($this->render($request, $incident, false));
    }

    public function pdf(Request $request, HsIncident $incident): Response
    {
        $html = $this->render($request, $incident, true);

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);

        $pdf = new Dompdf($options);
        $pdf->loadHtml($html);
        $pdf->setPaper('a4', 'portrait');
        $pdf->render();

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.Str::slug($incident->reference).'.pdf"',
        ]);
    }

    public function attachment(Request $request, HsIncidentAttachment $attachment): StreamedResponse
    {
        $this->enforceModule($request, Permission::MODULE_HEALTH_SAFETY);

        $attachment->loadMissing('incident');
        abort_unless($this->visibility->canView($request->user(), $attachment->incident), 403, 'This photo is not available to you.');

        $disk = $this->workflow->disk();
        abort_unless($disk->exists($attachment->path), 404);

        return $disk->response($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=0, no-store',
        ], 'inline');
    }

    protected function render(Request $request, HsIncident $incident, bool $pdf): string
    {
        $this->enforceModule($request, Permission::MODULE_HEALTH_SAFETY);

        $user = $request->user();
        abort_unless($this->visibility->canView($user, $incident), 403, 'This incident is not available to you.');

        return view('health_safety.incident-print', [
            'incident' => $this->visibility->present($user, $incident),
            'pdf' => $pdf,
            'printedBy' => $user->full_name ?? $user->email,
        ])->render();
    }
}
