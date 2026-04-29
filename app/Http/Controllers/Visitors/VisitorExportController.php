<?php

namespace App\Http\Controllers\Visitors;

use App\Exports\Visitors\VisitorsExport;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Visitor;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class VisitorExportController extends Controller
{
    public function excel(Request $request)
    {
        $date = $request->date('date')?->toDateString() ?? today()->toDateString();
        $visitors = $this->queryForDate($date)->get();

        AuditLog::record('export_visitors_excel', 'visitors', 'visitors', null, null, ['date' => $date]);

        return Excel::download(
            new VisitorsExport($visitors),
            'visitors_' . str_replace('-', '_', $date) . '.xlsx'
        );
    }

    public function pdf(Request $request)
    {
        $date = $request->date('date')?->toDateString() ?? today()->toDateString();
        $visitors = $this->queryForDate($date)->get();

        AuditLog::record('export_visitors_pdf', 'visitors', 'visitors', null, null, ['date' => $date]);

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);

        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('visitors.exports.pdf', [
            'visitors' => $visitors,
            'date' => $date,
        ])->render());
        $pdf->setPaper('a4', 'landscape');
        $pdf->render();

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="visitors_' . str_replace('-', '_', $date) . '.pdf"',
        ]);
    }

    protected function queryForDate(string $date)
    {
        return Visitor::query()
            ->with(['staff.department'])
            ->whereDate('check_in_at', $date)
            ->latest('check_in_at');
    }
}
