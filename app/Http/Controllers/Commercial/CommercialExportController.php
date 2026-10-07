<?php

namespace App\Http\Controllers\Commercial;

use App\Exports\Commercial\CommercialReportExport;
use App\Http\Controllers\Concerns\EnforcesModuleAccess;
use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Services\Commercial\CommercialExportService;
use App\Services\Commercial\CommercialReportData;
use App\Support\Audit;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Excel / PDF downloads of the Commercial reports. Every file is built from the same scoped data as the screen it comes
 * from (CommercialReportData), so a regional user only ever gets their own region and the numbers match the page.
 *
 * Route middleware requires commercial.export_reports; this controller then checks the extra permission each report
 * needs (individual reader figures need view_reader_performance, and so on) and answers 403 without it.
 */
class CommercialExportController extends Controller
{
    use EnforcesModuleAccess;

    public function download(Request $request, string $report, string $format, CommercialReportData $data, CommercialExportService $exports)
    {
        $this->enforceModule($request, Permission::MODULE_COMMERCIAL);

        $definition = CommercialExportService::REPORTS[$report] ?? abort(404);
        abort_unless(in_array($format, $definition['formats'], true), 404);

        foreach ($definition['permissions'] as $slug) {
            abort_unless($data->can($slug), 403, 'You do not have permission to export this report.');
        }

        abort_if($definition['any'] !== [] && ! collect($definition['any'])->contains(fn (string $slug) => $data->can($slug)), 403, 'You do not have permission to export this report.');

        $built = $this->build($request, $report, $data, $exports);

        if ($built === null) {
            return redirect()->back(fallback: route('commercial.home'))->with('error', 'There is nothing to export yet.');
        }

        [$result, $filters] = $built;
        // A PDF is far heavier to build than a spreadsheet, so it has its own, lower cap.
        $isPdf = $format === 'pdf';
        $cap = $isPdf ? (int) config('gwl.commercial_export_pdf_max_rows', 1500) : (int) config('gwl.commercial_export_max_rows', 5000);

        if ($result['row_count'] > $cap) {
            return redirect()->back(fallback: route('commercial.home'))->with('error', 'This '.($isPdf ? 'PDF' : 'export').' has '.number_format($result['row_count']).' rows, over the '.($isPdf ? 'PDF' : 'Excel').' limit of '.number_format($cap).'. Narrow the filters (a district, a shorter range of months)'.($isPdf && $result['row_count'] <= (int) config('gwl.commercial_export_max_rows', 5000) ? ' or download the Excel file instead' : '').' and try again.');
        }

        Audit::log(
            action: 'commercial.export_'.str_replace('-', '_', $report).'_'.$format,
            module: Permission::MODULE_COMMERCIAL,
            metadata: ['report' => $result['title'], 'format' => $format, 'filters' => $filters, 'rows' => $result['row_count']]
        );

        $generatedAt = now();
        $filename = $this->filename($report, $result, $format === 'excel' ? 'xlsx' : 'pdf', $generatedAt);

        if ($format === 'excel') {
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

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}|null the report and the filters it was made with
     */
    protected function build(Request $request, string $report, CommercialReportData $data, CommercialExportService $exports): ?array
    {
        $region = ctype_digit((string) $request->query('region')) && $request->query('region') !== '' ? (int) $request->query('region') : null;
        $district = ctype_digit((string) $request->query('district')) && $request->query('district') !== '' ? (int) $request->query('district') : null;
        $from = $this->month($request->query('from'));
        $to = $this->month($request->query('to'));
        $snapshot = $request->query('snapshot') !== null ? (string) $request->query('snapshot') : null;

        $months = ($from || $to) ? ($from ?? 'start').' to '.($to ?? 'latest') : 'All months';
        $readingMeta = fn () => [
            'Region' => $region && $data->seesAllRegions() ? (\App\Models\Region::query()->whereKey($region)->value('region_name') ?? $data->regionLabelFor()) : $data->regionLabelFor(),
            'Months' => $months,
            'Home district' => $district ? (\App\Models\District::query()->whereKey($district)->value('district_name') ?? 'Unknown') : 'Any',
        ];

        return match ($report) {
            'summary' => [$exports->summary($data->summary($snapshot)), ['snapshot' => $snapshot]],
            'reading-trend' => [$exports->readingTrend($data->readingTrend($region, $district, $from, $to), $readingMeta()), compact('region', 'district', 'from', 'to')],
            'readers' => [$exports->readers($data->readers($region, $district, $from, $to), $readingMeta()), compact('region', 'district', 'from', 'to')],
            'billing' => ($billing = $data->billingSnapshot($snapshot, (string) $request->query('district', ''))) === null ? null : [$exports->billing($billing), ['snapshot' => $billing['snapshot']['id'], 'district' => $billing['district']]],
            'scorecard' => ($card = $data->scorecard($snapshot)) === null ? null : [$exports->scorecard($card), ['snapshot' => $card['snapshot']['id']]],
            default => null,
        };
    }

    protected function month(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-(0[1-9]|1[0-2])/', $value) ? substr($value, 0, 7) : null;
    }

    /** commercial_billing_<region>_<period>_<yyyymmdd>.xlsx */
    protected function filename(string $report, array $result, string $extension, \Illuminate\Support\Carbon $when): string
    {
        $slug = fn (string $text) => Str::slug($text, '-') ?: 'all';

        return 'commercial_'.str_replace('-', '_', $report).'_'.$slug($result['file']['region']).'_'.$slug($result['file']['period']).'_'.$when->format('Ymd').'.'.$extension;
    }
}
