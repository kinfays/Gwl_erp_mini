<?php

namespace App\Http\Controllers\Finance;

use App\Exports\Commercial\CommercialReportExport;
use App\Http\Controllers\Concerns\EnforcesModuleAccess;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\District;
use App\Models\Permission;
use App\Models\Region;
use App\Services\Finance\FinanceExportService;
use App\Services\Finance\FinanceScope;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Excel / PDF downloads of the Finance and Audit reports. Every file is built by FinanceExportService from the same scoped data as the
 * screen it comes from (as Finance, or as Audit which never sees a draft), for ONE location: the user's own, or (super_admin only) the
 * district named in ?district=. The route needs {module}.export; this controller also needs {module}.view_reports and answers 403
 * without it. The workbook and the PDF page are the Commercial module's generic report classes (text cells stay text).
 *
 * One audit row per download: who, which report and format, the period, how many rows and the location; never a payee or an amount.
 */
class FinanceExportController extends Controller
{
    use EnforcesModuleAccess;

    public function finance(Request $request, string $report, string $format, FinanceScope $scope, FinanceExportService $exports)
    {
        $this->enforceModule($request, Permission::MODULE_FINANCE);

        return $this->serve($request, 'finance', FinanceScope::AS_FINANCE, $report, $format, $scope, $exports);
    }

    public function audit(Request $request, string $report, string $format, FinanceScope $scope, FinanceExportService $exports)
    {
        $this->enforceModule($request, Permission::MODULE_AUDIT);

        return $this->serve($request, 'audit', FinanceScope::AS_AUDIT, $report, $format, $scope, $exports);
    }

    protected function serve(Request $request, string $module, string $as, string $report, string $format, FinanceScope $scope, FinanceExportService $exports)
    {
        abort_unless(config('gwl.finance_audit_enabled'), 404);

        $user = $request->user();
        $definition = FinanceExportService::REPORTS[$report] ?? abort(404);
        abort_unless(in_array($format, $definition['formats'], true), 404);
        abort_unless($scope->can($user, $module.'.export') && $scope->can($user, $module.'.view_reports'), 403, 'You do not have permission to export this report.');

        $location = $this->location($request, $scope);

        if ($location === null) {
            return redirect()->back(fallback: route($module === 'audit' ? 'audit.reports' : 'finance.reports'))->with('error', 'Choose the location to export first.');
        }

        [$from, $to] = $this->period($request);
        $isPdf = $format === 'pdf';
        $cap = $isPdf ? (int) config('gwl.fin_export_pdf_max_rows', 1500) : (int) config('gwl.fin_export_max_rows', 5000);
        $result = $exports->build($report, $user, $as, $location, $from, $to, $this->label($location, $scope), $cap);

        if ($result['row_count'] > $cap) {
            return redirect()->back(fallback: route($module === 'audit' ? 'audit.reports' : 'finance.reports'))->with('error', 'This '.($isPdf ? 'PDF' : 'export').' has '.number_format($result['row_count']).' rows, over the limit of '.number_format($cap).'. Choose a shorter period and try again.');
        }

        AuditLog::record($module.'.export_'.$report.'_'.$format, $module, null, null, null, null, [
            'report' => $result['title'],
            'format' => $format,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'rows' => $result['row_count'],
            'region_id' => $location['region_id'],
            'district_id' => $location['district_id'],
        ]);

        $generatedAt = now();
        $filename = $module.'_pv_'.$report.'_'.(Str::slug($result['file']['region']) ?: 'all').'_'.$result['file']['period'].'_'.$generatedAt->format('Ymd').'.'.($isPdf ? 'pdf' : 'xlsx');

        if (! $isPdf) {
            return Excel::download(new CommercialReportExport($result, $generatedAt), $filename);
        }

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);

        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('commercial.exports.report-pdf', ['report' => $result, 'generatedAt' => $generatedAt])->render());
        $pdf->setPaper('a4', 'landscape');
        $pdf->render();

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /** @return array{region_id: int, district_id: int|null}|null */
    protected function location(Request $request, FinanceScope $scope): ?array
    {
        if (! $scope->seesAll($request->user())) {
            return $scope->ownLocation($request->user());
        }

        $district = ctype_digit((string) $request->query('district')) ? District::query()->find((int) $request->query('district')) : null;

        return $district ? ['region_id' => (int) $district->region_id, 'district_id' => (int) $district->id] : null;
    }

    protected function label(array $location, FinanceScope $scope): string
    {
        return $scope->isHeadOffice($location) ? 'Head Office' : (Region::query()->whereKey($location['region_id'])->value('region_name') ?? 'Location');
    }

    /** @return array{0: Carbon, 1: Carbon} the requested range, else the last twelve months */
    protected function period(Request $request): array
    {
        $date = function (mixed $value): ?Carbon {
            return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && ($parsed = Carbon::createFromFormat('Y-m-d', $value)) && $parsed->format('Y-m-d') === $value ? $parsed->startOfDay() : null;
        };

        $from = $date($request->query('from')) ?? today()->subMonthsNoOverflow(11)->startOfMonth();
        $to = $date($request->query('to')) ?? today();

        return $from->gt($to) ? [$to, $from] : [$from, $to];
    }
}
