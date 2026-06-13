<?php

namespace App\Http\Controllers\Transport;

use App\Exports\Transport\TransportReportExport;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Services\ReportsService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;

class TransportModuleController extends Controller
{
    public function home(): View
    {
        return view('transport.home');
    }

    public function vehicles(): View
    {
        return view('transport.vehicles');
    }

    public function mileage(): View
    {
        return view('transport.mileage');
    }

    public function issues(): View
    {
        return view('transport.issues');
    }

    public function maintenance(): View
    {
        return view('transport.maintenance');
    }

    public function expenses(): View
    {
        return view('transport.expenses');
    }

    public function reports(): View
    {
        return view('transport.reports');
    }

    public function reportPdf(Request $request, ReportsService $reports)
    {
        [$from, $to] = $reports->resolveDateRange(
            $request->string('date_preset', 'this_month')->toString(),
            $request->input('custom_from'),
            $request->input('custom_to')
        );
        $departmentId = $request->integer('department_id') ?: null;
        $payload = $reports->reportPayload($from, $to, $departmentId);
        $department = $departmentId ? Department::query()->find($departmentId) : null;

        $html = view('transport.exports.report-pdf', [
            'payload' => $payload,
            'from' => $from,
            'to' => $to,
            'department' => $department,
        ])->render();

        $options = new Options;
        $options->set('isRemoteEnabled', true);

        $pdf = new Dompdf($options);
        $pdf->loadHtml($html);
        $pdf->setPaper('A4', 'landscape');
        $pdf->render();

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="transport-report-'.now()->format('Ymd-His').'.pdf"',
        ]);
    }

    public function reportExcel(Request $request, ReportsService $reports)
    {
        [$from, $to] = $reports->resolveDateRange(
            $request->string('date_preset', 'this_month')->toString(),
            $request->input('custom_from'),
            $request->input('custom_to')
        );
        $departmentId = $request->integer('department_id') ?: null;

        return Excel::download(
            new TransportReportExport(
                $reports->reportPayload($from, $to, $departmentId),
                [
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                    'department_id' => $departmentId,
                ]
            ),
            'transport-report-'.now()->format('Ymd-His').'.xlsx'
        );
    }
}
