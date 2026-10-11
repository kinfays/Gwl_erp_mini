<?php

namespace App\Http\Controllers\GWater;

use App\Exports\Commercial\CommercialReportExport;
use App\Http\Controllers\Concerns\EnforcesModuleAccess;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\District;
use App\Models\Permission;
use App\Models\Region;
use App\Services\GWater\GwaterExportService;
use App\Services\GWater\GwaterVisibility;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Excel / PDF downloads of the G-Water reports. Every file is built by GwaterExportService from the same scoped data as the
 * screen it comes from, for ONE location: the user's own, or (super_admin only) the district named in ?district=. The route
 * needs gwater.export; this controller also needs gwater.view_reports and answers 403 without them.
 *
 * The workbook and the PDF page are the Commercial module's generic report classes: they take a titled-tables array and keep
 * text cells as text (a value starting = + - @ is never turned into a formula).
 */
class GwaterExportController extends Controller
{
    use EnforcesModuleAccess;

    public function download(Request $request, string $report, string $format, GwaterVisibility $visibility, GwaterExportService $exports)
    {
        $this->enforceModule($request, Permission::MODULE_GWATER);
        abort_unless(config('gwl.gwater_module_enabled'), 404);

        $user = $request->user();
        $definition = GwaterExportService::REPORTS[$report] ?? abort(404);
        abort_unless(in_array($format, $definition['formats'], true), 404);
        abort_unless($visibility->can($user, 'gwater.export') && $visibility->can($user, 'gwater.view_reports'), 403, 'You do not have permission to export this report.');

        $location = $this->location($request, $visibility);

        if ($location === null) {
            return redirect()->back(fallback: route('gwater.home'))->with('error', 'Choose the location to export first.');
        }

        [$from, $to] = $this->period($request);
        $result = $exports->build($report, $location, $from, $to, $user, $this->label($location, $visibility));

        $isPdf = $format === 'pdf';
        $cap = $isPdf ? (int) config('gwl.gwater_export_pdf_max_rows', 1500) : (int) config('gwl.gwater_export_max_rows', 5000);

        if ($result['row_count'] > $cap) {
            return redirect()->back(fallback: route('gwater.home'))->with('error', 'This '.($isPdf ? 'PDF' : 'export').' has '.number_format($result['row_count']).' rows, over the limit of '.number_format($cap).'. Choose a shorter period and try again.');
        }

        // Who, which report, which period and how many rows: never a name, a number or an amount.
        AuditLog::record('gwater.export_'.$report.'_'.$format, 'gwater', null, null, null, null, [
            'report' => $result['title'],
            'format' => $format,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'rows' => $result['row_count'],
            'region_id' => $location['region_id'],
            'district_id' => $location['district_id'],
        ]);

        $generatedAt = now();
        $filename = 'gwater_'.str_replace('-', '_', $report).'_'.(Str::slug($result['file']['region']) ?: 'all').'_'.$result['file']['period'].'_'.$generatedAt->format('Ymd').'.'.($isPdf ? 'pdf' : 'xlsx');

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
    protected function location(Request $request, GwaterVisibility $visibility): ?array
    {
        if (! $visibility->seesAll($request->user())) {
            return $visibility->ownLocation($request->user());
        }

        $district = ctype_digit((string) $request->query('district')) ? District::query()->find((int) $request->query('district')) : null;

        return $district ? ['region_id' => (int) $district->region_id, 'district_id' => (int) $district->id] : null;
    }

    protected function label(array $location, GwaterVisibility $visibility): string
    {
        return $visibility->isHeadOffice($location) ? 'Head Office' : (Region::query()->whereKey($location['region_id'])->value('region_name') ?? 'Location');
    }

    /** @return array{0: Carbon, 1: Carbon} the requested range, else the current month */
    protected function period(Request $request): array
    {
        $date = function (mixed $value): ?Carbon {
            return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && ($parsed = Carbon::createFromFormat('Y-m-d', $value)) && $parsed->format('Y-m-d') === $value ? $parsed->startOfDay() : null;
        };

        $from = $date($request->query('from')) ?? today()->startOfMonth();
        $to = $date($request->query('to')) ?? today();

        return $from->gt($to) ? [$to, $from] : [$from, $to];
    }
}
