<?php

namespace App\Http\Controllers\HealthSafety;

use App\Exports\HealthSafety\HealthSafetyReportExport;
use App\Http\Controllers\Concerns\EnforcesModuleAccess;
use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Services\HealthSafety\ExpiryRegisterService;
use App\Services\HealthSafety\HealthSafetyExportService;
use App\Support\Audit;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Every Health & Safety export. Authorised here (export_reports plus the permission of the screen the report belongs to),
 * built by HealthSafetyExportService from the same query as the screen, capped, and audited. Not a public URL.
 */
class ExportController extends Controller
{
    use EnforcesModuleAccess;

    public function show(Request $request, HealthSafetyExportService $exports, string $report)
    {
        $this->enforceModule($request, Permission::MODULE_HEALTH_SAFETY);

        $user = $request->user();
        abort_unless(isset(HealthSafetyExportService::REPORTS[$report]), 404);
        abort_unless($exports->allowed($user, $report), 403, 'You may not take this export.');

        $filters = collect($request->query())->except(['report'])->filter(fn ($value) => is_scalar($value) && $value !== '')->all();
        $result = $exports->build($user, $report, $filters);

        if (isset($result['over'])) {
            return redirect()->back(fallback: route('health_safety.home'))->with('error', 'This export has '.number_format($result['over']).' rows, over the limit of '.number_format($result['cap']).'. Narrow the filters and try again.');
        }

        $pdf = $report === 'expiry-register-pdf';

        Audit::log('health_safety.export', Permission::MODULE_HEALTH_SAFETY, null, null, [
            'report' => $report,
            'rows' => $result['count'],
            'filters' => $filters,
            'format' => $pdf ? 'pdf' : 'xlsx',
        ]);

        $name = 'health-safety-'.$report.'-'.now()->format('Ymd-His');

        if (! $pdf) {
            return Excel::download(new HealthSafetyReportExport($result, $user->full_name ?? $user->staff_id, now(), $filters), $name.'.xlsx');
        }

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('health_safety.exports.expiry-walkround', [
            'groups' => $result['register']->sortBy(fn (array $row) => [$row['site'], $row['days']])->groupBy('site')->map->values()->all(),
            'count' => $result['count'],
            'buckets' => ExpiryRegisterService::BUCKETS,
            'generatedAt' => now(),
            'generatedBy' => $user->full_name ?? $user->staff_id,
        ])->render());
        $dompdf->setPaper('a4', 'portrait');
        $dompdf->render();

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
