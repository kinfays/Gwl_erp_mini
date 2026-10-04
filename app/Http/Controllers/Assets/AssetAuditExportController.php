<?php

namespace App\Http\Controllers\Assets;

use App\Exports\Assets\AssetAuditExport;
use App\Http\Controllers\Concerns\EnforcesModuleAccess;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\IctAssetAudit;
use App\Models\Permission;
use App\Services\Assets\AssetAuditService;
use App\Services\Assets\AuditVisibility;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/** A completed audit as Excel or PDF. In-progress audits have nothing final to export, so they are refused. */
class AssetAuditExportController extends Controller
{
    use EnforcesModuleAccess;

    public function excel(Request $request, IctAssetAudit $audit, AssetAuditService $service)
    {
        $rows = $this->rowsFor($request, $audit, $service);

        $this->audit('export_asset_audit_excel', $audit, $rows->count());

        return Excel::download(new AssetAuditExport($rows), $this->filename($audit, 'xlsx'));
    }

    public function pdf(Request $request, IctAssetAudit $audit, AssetAuditService $service)
    {
        $rows = $this->rowsFor($request, $audit, $service);

        $this->audit('export_asset_audit_pdf', $audit, $rows->count());

        $audit->loadMissing(['startedBy', 'region', 'district']);

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);

        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('assets.exports.audit-pdf', [
            'audit' => $audit,
            'summary' => $service->summary($audit),
            'columns' => $service->columns(),
            'rows' => $rows,
            'generatedAt' => now(),
        ])->render());
        $pdf->setPaper('a4', 'landscape');
        $pdf->render();

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->filename($audit, 'pdf').'"',
        ]);
    }

    protected function rowsFor(Request $request, IctAssetAudit $audit, AssetAuditService $service)
    {
        $this->enforceModule($request, 'assets');

        abort_unless(AuditVisibility::canSee($request->user(), $audit), 404);

        if (! $audit->isCompleted()) {
            abort(409, 'Complete the audit before exporting it.');
        }

        return $service->exportRows($audit);
    }

    protected function audit(string $action, IctAssetAudit $audit, int $rows): void
    {
        AuditLog::record($action, Permission::MODULE_ASSETS, 'ict_asset_audits', $audit->id, null, ['rows' => $rows]);
    }

    protected function filename(IctAssetAudit $audit, string $extension): string
    {
        $slug = preg_replace('/[^A-Za-z0-9]+/', '_', $audit->title) ?: 'audit';

        return 'asset_audit_'.trim($slug, '_').'_'.$audit->id.'.'.$extension;
    }
}
