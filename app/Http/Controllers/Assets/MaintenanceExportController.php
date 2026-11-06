<?php

namespace App\Http\Controllers\Assets;

use App\Exports\Assets\MaintenanceExport;
use App\Http\Controllers\Concerns\EnforcesModuleAccess;
use App\Http\Controllers\Controller;
use App\Livewire\Assets\Concerns\ScopesAssetsByActor;
use App\Models\AuditLog;
use App\Models\IctAssetMaintenance;
use App\Models\Permission;
use App\Services\Assets\MaintenanceListService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/**
 * The Maintenance log as Excel or PDF: the rows its filters leave. The query is built exactly as the screen builds it
 * (same filters through MaintenanceListService, same scopeAssetsForViewing()), so a file can never hold a row the user
 * could not see on screen. The route middleware holds assets.export_maintenance; this class does the rest.
 */
class MaintenanceExportController extends Controller
{
    use EnforcesModuleAccess;
    use ScopesAssetsByActor;

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
        $service = app(MaintenanceListService::class);

        $query = $service->apply($this->baseQuery(), $filters)->latest()->orderByDesc('id');
        $count = (clone $query)->count();
        $cap = (int) config($format === 'pdf' ? 'gwl.assets_maintenance_export_pdf_max_rows' : 'gwl.assets_maintenance_export_max_rows');

        if ($count > $cap) {
            return redirect()->back(fallback: route('assets.maintenance'))->with('error', $format === 'pdf'
                ? "This list has {$count} rows, over the PDF limit of {$cap}. Narrow the filters, or export it to Excel (limit ".number_format((int) config('gwl.assets_maintenance_export_max_rows')).' rows).'
                : "This list has {$count} rows, over the Excel limit of {$cap}. Narrow the filters and try again.");
        }

        $headings = $service->headings();
        $rows = $query->get()->map(fn (IctAssetMaintenance $record) => $service->row($record));

        // Counts and the names of the filters in force: what was typed into the search box is free text and stays out.
        AuditLog::record(
            'export_maintenance_'.$format,
            Permission::MODULE_ASSETS,
            'ict_asset_maintenances',
            null,
            null,
            [
                'filters' => array_keys(array_filter($filters, fn ($value) => filled($value) && $value !== false)),
                'rows' => $count,
            ]
        );

        $name = 'maintenance_log_'.now()->format('Y_m_d');

        if ($format === 'excel') {
            return Excel::download(new MaintenanceExport($headings, $rows), $name.'.xlsx');
        }

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);

        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('exports.list-pdf', [
            'title' => 'Maintenance log',
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

    /** Every maintenance record on an asset the actor may view (all regions for Head Office ICT), with what the file needs. */
    protected function baseQuery(): Builder
    {
        return IctAssetMaintenance::query()
            ->with(['asset.region', 'asset.district'])
            ->whereHas('asset', fn ($assetQuery) => $this->scopeAssetsForViewing($assetQuery));
    }

    /** @return array{search: string, status: string, type: string, due: bool} */
    protected function filters(Request $request): array
    {
        $text = fn (string $key): string => is_scalar($request->query($key)) ? trim((string) $request->query($key)) : '';

        return [
            'search' => $text('q'),
            'status' => $text('status'),
            'type' => $text('type'),
            'due' => filter_var($request->query('due'), FILTER_VALIDATE_BOOLEAN),
        ];
    }

    protected function summaryLine(array $filters): string
    {
        $parts = [];

        foreach ($filters as $name => $value) {
            if ($name === 'due') {
                if ($value) {
                    $parts[] = 'due only (open tickets)';
                }

                continue;
            }

            if (filled($value)) {
                $parts[] = ($name === 'type' ? 'maintenance type' : $name).' '.$value;
            }
        }

        return $parts === [] ? 'No filters: every record you may see.' : 'Filters: '.implode('; ', $parts);
    }
}
