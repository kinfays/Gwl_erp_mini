<?php

namespace App\Http\Controllers\Letters;

use App\Exceptions\Letters\RegisterTooLargeException;
use App\Exports\Letters\LetterRegisterExport;
use App\Http\Controllers\Concerns\EnforcesModuleAccess;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Services\Letters\LetterRegisterService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * The letter register as Excel and PDF. Always the signed-in user's own register; only a super_admin may name another
 * employee (employee_id), and everyone else who tries gets a 403.
 */
class LetterRegisterExportController extends Controller
{
    use EnforcesModuleAccess;

    public function excel(Request $request, LetterRegisterService $register)
    {
        [$holder, $from, $to, $scope] = $this->context($request, $register);

        if (! $rows = $this->rowsOrRedirect($register, $holder, $from, $to, $scope)) {
            return $this->narrowRange($from, $to, $scope);
        }

        $this->audit('export_letter_register_excel', $holder, $from, $to, $scope, $rows->count());

        return Excel::download(new LetterRegisterExport($rows), $this->filename('xlsx', $holder, $from, $to));
    }

    public function pdf(Request $request, LetterRegisterService $register)
    {
        [$holder, $from, $to, $scope] = $this->context($request, $register);

        if (! $rows = $this->rowsOrRedirect($register, $holder, $from, $to, $scope)) {
            return $this->narrowRange($from, $to, $scope);
        }

        $this->audit('export_letter_register_pdf', $holder, $from, $to, $scope, $rows->count());

        $holder->loadMissing(['department', 'district', 'region']);

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);

        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('letters.exports.register-pdf', [
            'holder' => $holder,
            'rows' => $rows->map(fn (array $row) => $register->cells($row, 120)),
            'periodLabel' => $this->dateLabel($from, $to),
            'scopeLabel' => LetterRegisterService::SCOPES[$scope],
            'generatedAt' => now(),
        ])->render());
        $pdf->setPaper('a4', 'landscape');
        $pdf->render();

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->filename('pdf', $holder, $from, $to).'"',
        ]);
    }

    /** @return array{0: Employee, 1: string, 2: string, 3: string} holder, from, to (Y-m-d), scope */
    protected function context(Request $request, LetterRegisterService $register): array
    {
        $this->enforceModule($request, 'letters');

        $user = $request->user();

        if ($request->has('employee_id')) {
            abort_unless($user->hasRoles('super_admin'), 403, 'You can only export your own register.');
            $holder = Employee::query()->findOrFail((int) $request->input('employee_id'));
        } else {
            $holder = $user->employee ?? $user->employeeByStaffId;
            abort_if(! $holder, 403, 'Your user account is not linked to an employee record.');
        }

        [$from, $to] = $this->rangeFromRequest($request);

        return [$holder, $from, $to, $register->normalizeScope($request->input('scope'))];
    }

    /** The rows, or null when the range is over the row cap (the user is sent back to narrow it). */
    protected function rowsOrRedirect(LetterRegisterService $register, Employee $holder, string $from, string $to, string $scope)
    {
        try {
            return $register->rows($holder, Carbon::parse($from), Carbon::parse($to), $scope);
        } catch (RegisterTooLargeException $e) {
            session()->flash('error', $e->getMessage());

            return null;
        }
    }

    protected function narrowRange(string $from, string $to, string $scope)
    {
        return redirect()->route('letters.register', ['from' => $from, 'to' => $to, 'scope' => $scope]);
    }

    protected function audit(string $action, Employee $holder, string $from, string $to, string $scope, int $rows): void
    {
        AuditLog::record($action, 'letters', 'letter_status_logs', null, null, [
            'employee_id' => $holder->id,
            'from' => $from,
            'to' => $to,
            'scope' => $scope,
            'rows' => $rows,
        ]);
    }

    /** The requested range on date received, defaulting to the current month. */
    protected function rangeFromRequest(Request $request): array
    {
        $from = $this->parseDate($request->input('from')) ?? today()->startOfMonth()->toDateString();
        $to = $this->parseDate($request->input('to')) ?? today()->endOfMonth()->toDateString();

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
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

    protected function dateLabel(string $from, string $to): string
    {
        if ($from === $to) {
            return Carbon::parse($from)->format('l, d F Y');
        }

        return Carbon::parse($from)->format('d F Y').' - '.Carbon::parse($to)->format('d F Y');
    }

    protected function filename(string $extension, Employee $holder, string $from, string $to): string
    {
        $staffId = preg_replace('/[^A-Za-z0-9]+/', '', (string) $holder->staff_id) ?: 'unknown';

        return 'letter_register_'.$staffId.'_'.str_replace('-', '_', $from).'_to_'.str_replace('-', '_', $to).'.'.$extension;
    }
}
