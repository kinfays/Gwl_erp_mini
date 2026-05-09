<?php

namespace App\Http\Controllers\Visitors;

use App\Exports\Visitors\VisitorsExport;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Visitor;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class VisitorExportController extends Controller
{
    public function excel(Request $request)
    {
        [$startDate, $endDate] = $this->rangeFromRequest($request);
        $visitors = $this->queryForRange($startDate, $endDate)->get();

        AuditLog::record('export_visitors_excel', 'visitors', 'visitors', null, null, [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        return Excel::download(
            new VisitorsExport($visitors),
            $this->filename('xlsx', $startDate, $endDate)
        );
    }

    public function pdf(Request $request)
    {
        [$startDate, $endDate] = $this->rangeFromRequest($request);
        $visitors = $this->queryForRange($startDate, $endDate)->get();

        AuditLog::record('export_visitors_pdf', 'visitors', 'visitors', null, null, [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);

        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('visitors.exports.pdf', [
            'visitors' => $visitors,
            'dateLabel' => $this->dateLabel($startDate, $endDate),
        ])->render());
        $pdf->setPaper('a4', 'landscape');
        $pdf->render();

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->filename('pdf', $startDate, $endDate).'"',
        ]);
    }

    protected function queryForRange(string $startDate, string $endDate)
    {
        return Visitor::query()
            ->with(['staff.department'])
            ->whereBetween('check_in_at', [
                Carbon::parse($startDate)->startOfDay(),
                Carbon::parse($endDate)->endOfDay(),
            ])
            ->latest('check_in_at');
    }

    protected function rangeFromRequest(Request $request): array
    {
        $requestedStartDate = $this->parseDate($request->input('start_date'));
        $requestedEndDate = $this->parseDate($request->input('end_date'));
        $singleDate = $this->parseDate($request->input('date'));

        if ($requestedStartDate || $requestedEndDate) {
            $startDate = $requestedStartDate ?? $requestedEndDate;
            $endDate = $requestedEndDate ?? $requestedStartDate;
        } else {
            $startDate = $singleDate ?? today()->toDateString();
            $endDate = $startDate;
        }

        if ($startDate > $endDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        return [$startDate, $endDate];
    }

    protected function parseDate(mixed $date): ?string
    {
        if (! is_string($date) || $date === '') {
            return null;
        }

        try {
            return Carbon::parse($date)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    protected function dateLabel(string $startDate, string $endDate): string
    {
        if ($startDate === $endDate) {
            return Carbon::parse($startDate)->format('l, d F Y');
        }

        return Carbon::parse($startDate)->format('d F Y').' - '.Carbon::parse($endDate)->format('d F Y');
    }

    protected function filename(string $extension, string $startDate, string $endDate): string
    {
        $suffix = $startDate === $endDate
            ? str_replace('-', '_', $startDate)
            : str_replace('-', '_', $startDate).'_to_'.str_replace('-', '_', $endDate);

        return 'visitors_'.$suffix.'.'.$extension;
    }
}
