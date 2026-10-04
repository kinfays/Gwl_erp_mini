<?php

namespace App\Http\Controllers\Assets;

use App\Exports\Assets\AssetSummaryExport;
use App\Http\Controllers\Concerns\EnforcesModuleAccess;
use App\Http\Controllers\Controller;
use App\Livewire\Assets\Concerns\ScopesAssetsByActor;
use App\Models\AuditLog;
use App\Models\District;
use App\Models\IctAsset;
use App\Models\IctAssetIssueReport;
use App\Models\Permission;
use App\Services\Assets\AssetSummaryService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/**
 * The Asset Summary page as Excel or PDF, for the same district and date filters the page offers. Scoped exactly like
 * the page itself (a regional ICT user only gets their region).
 */
class AssetSummaryExportController extends Controller
{
    use EnforcesModuleAccess;
    use ScopesAssetsByActor;

    public function excel(Request $request, AssetSummaryService $summary)
    {
        [$report, $filters] = $this->build($request, $summary);

        $this->audit('export_asset_summary_excel', $filters);

        return Excel::download(new AssetSummaryExport($report), 'asset_summary_'.now()->format('Y_m_d').'.xlsx');
    }

    public function pdf(Request $request, AssetSummaryService $summary)
    {
        [$report, $filters] = $this->build($request, $summary);

        $this->audit('export_asset_summary_pdf', $filters);

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);

        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('assets.exports.summary-pdf', [
            'tables' => AssetSummaryExport::tables($report),
            'filters' => $filters,
            'generatedAt' => now(),
        ])->render());
        $pdf->setPaper('a4', 'portrait');
        $pdf->render();

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="asset_summary_'.now()->format('Y_m_d').'.pdf"',
        ]);
    }

    /** @return array{0: array, 1: array{district: ?string, from: ?string, to: ?string}} */
    protected function build(Request $request, AssetSummaryService $summary): array
    {
        $this->enforceModule($request, 'assets');

        $districtId = ctype_digit((string) $request->query('district')) ? (int) $request->query('district') : null;
        $from = AssetSummaryService::parseDate($request->query('from'));
        $to = AssetSummaryService::parseDate($request->query('to'));

        $report = $summary->report(
            $this->scopeAssetsForViewing(IctAsset::query()),
            $this->scopeReportsForViewing(IctAssetIssueReport::query()),
            $districtId,
            $from,
            $to,
        );

        return [$report, [
            'district' => $districtId ? District::query()->whereKey($districtId)->value('district_name') : null,
            'from' => $from,
            'to' => $to,
        ]];
    }

    protected function audit(string $action, array $filters): void
    {
        AuditLog::record($action, Permission::MODULE_ASSETS, 'ict_assets', null, null, $filters);
    }
}
