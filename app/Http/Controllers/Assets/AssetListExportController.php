<?php

namespace App\Http\Controllers\Assets;

use App\Exports\ListExport;
use App\Http\Controllers\Concerns\EnforcesModuleAccess;
use App\Http\Controllers\Controller;
use App\Livewire\Assets\Concerns\ScopesAssetsByActor;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Services\Assets\AssetListColumns;
use App\Support\ListColumns;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/**
 * One Assets list as Excel or PDF: the rows its filters leave, in exactly the columns the user has ticked (?cols=,
 * parsed by ListColumns like the on-screen list). Scoped through the same trait as the lists, so a regional ICT user
 * only ever exports their region. The route middleware holds the export permission; this class does the rest.
 */
abstract class AssetListExportController extends Controller
{
    use EnforcesModuleAccess;
    use ScopesAssetsByActor;

    /** Request parameters that are list filters, and the filter name each one has in AssetListQuery. */
    protected const FILTER_PARAMS = [
        'q' => 'search',
        'status' => 'status',
        'type' => 'type',
        'district' => 'district',
        'ups' => 'ups',
        'age_min' => 'age_min',
        'age_max' => 'age_max',
        'age' => 'age',
        'warranty' => 'warranty',
        'replacement_bucket' => 'replacement_bucket',
        'assigned' => 'assigned',
    ];

    /** AssetListColumns::ASSETS / PHONES / NETWORK / REPORTS. */
    abstract protected function listKey(): string;

    abstract protected function title(): string;

    /** Used in the audit action (export_<slug>_excel) and the file name. */
    abstract protected function slug(): string;

    /** The table the exported rows come from, for the audit row. */
    abstract protected function table(): string;

    /** The list screen the user is sent back to when an export is refused. */
    abstract protected function listRoute(): string;

    /** The scoped, filtered, ordered query (not yet limited or executed). */
    abstract protected function query(array $filters): Builder;

    public function excel(Request $request)
    {
        return $this->export($request, 'excel');
    }

    public function pdf(Request $request)
    {
        return $this->export($request, 'pdf');
    }

    protected function export(Request $request, string $format)
    {
        $this->enforceModule($request, 'assets');

        $filters = $this->filters($request);
        $manifest = AssetListColumns::manifest($this->listKey());

        // exportable() drops actions and any column marked export => false (Network credentials): a downloaded file is
        // forwarded, so a password column never goes in one, whatever ?cols= asks for.
        $keys = ListColumns::exportable($manifest, $request->query('cols'));

        $query = $this->query($filters);
        $count = (clone $query)->count();
        $cap = (int) config($format === 'pdf' ? 'gwl.assets_list_export_pdf_max_rows' : 'gwl.assets_list_export_max_rows');

        if ($count > $cap) {
            return redirect()->back(fallback: route($this->listRoute()))->with('error', $format === 'pdf'
                ? "This list has {$count} rows, over the PDF limit of {$cap}. Narrow the filters, or export it to Excel (limit ".number_format((int) config('gwl.assets_list_export_max_rows')).' rows).'
                : "This list has {$count} rows, over the Excel limit of {$cap}. Narrow the filters and try again.");
        }

        // Each on-screen column may become several file columns (name and serial, type and model, ...).
        $headings = AssetListColumns::exportHeadings($this->listKey(), $keys);
        $rows = $query->get()->map(fn ($row) => AssetListColumns::exportRow($this->listKey(), $keys, $row));

        AuditLog::record(
            'export_'.$this->slug().'_'.$format,
            Permission::MODULE_ASSETS,
            $this->table(),
            null,
            null,
            ['filters' => array_filter($filters, fn ($value) => filled($value)), 'columns' => $keys, 'rows' => $count]
        );

        $name = $this->slug().'_'.now()->format('Y_m_d');

        if ($format === 'excel') {
            return Excel::download(new ListExport($headings, $rows), $name.'.xlsx');
        }

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);

        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('exports.list-pdf', [
            'title' => $this->title(),
            'summary' => $this->summaryLine($filters),
            'headings' => $headings,
            'rows' => $rows,
            'rowCount' => $count,
            'generatedAt' => now(),
        ])->render());
        $pdf->setPaper('a4', 'landscape');
        $pdf->render();

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'.pdf"',
        ]);
    }

    /** @return array<string, string> the list filters named as AssetListQuery names them; blank when not sent */
    protected function filters(Request $request): array
    {
        $filters = [];

        foreach (self::FILTER_PARAMS as $param => $filter) {
            $value = $request->query($param);
            $filters[$filter] = is_scalar($value) ? trim((string) $value) : '';
        }

        return $filters;
    }

    /** One line saying what the file holds, e.g. "Filters: status Damaged; search "dell"". */
    protected function summaryLine(array $filters): string
    {
        $parts = [];

        foreach (array_filter($filters, fn ($value) => filled($value)) as $name => $value) {
            $parts[] = str_replace('_', ' ', $name).' '.$value;
        }

        return $parts === [] ? 'No filters: every row you may see.' : 'Filters: '.implode('; ', $parts);
    }
}
