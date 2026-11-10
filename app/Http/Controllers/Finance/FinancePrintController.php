<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Concerns\EnforcesModuleAccess;
use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Services\Finance\FinanceScope;
use App\Services\Finance\PrintLimitException;
use App\Services\Finance\PrintSelection;
use App\Services\Finance\PvPrintService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * The PDF of a voucher, a batch, or a ticked selection, as Finance ("Finance copy") or as Audit ("Audit copy"). Thin: what the user may
 * print is PvPrintService / FinanceScope's (404 outside their reach, the same lookups as the screens). The PDF opens in the browser; the
 * print is recorded (audit row, print counts) only after it has been made.
 */
class FinancePrintController extends Controller
{
    use EnforcesModuleAccess;

    public function financeVoucher(Request $request, int $pv, PvPrintService $prints)
    {
        return $this->serve($request, FinanceScope::AS_FINANCE, $prints, fn () => $prints->vouchers($request->user(), FinanceScope::AS_FINANCE, [$pv]));
    }

    public function auditVoucher(Request $request, int $pv, PvPrintService $prints)
    {
        return $this->serve($request, FinanceScope::AS_AUDIT, $prints, fn () => $prints->vouchers($request->user(), FinanceScope::AS_AUDIT, [$pv]));
    }

    public function financeBatch(Request $request, int $batch, PvPrintService $prints)
    {
        return $this->serve($request, FinanceScope::AS_FINANCE, $prints, fn () => $prints->batch($request->user(), FinanceScope::AS_FINANCE, $batch, $request->boolean('pages')));
    }

    public function auditBatch(Request $request, int $batch, PvPrintService $prints)
    {
        return $this->serve($request, FinanceScope::AS_AUDIT, $prints, fn () => $prints->batch($request->user(), FinanceScope::AS_AUDIT, $batch, $request->boolean('pages')));
    }

    public function financeSelected(Request $request, string $token, PvPrintService $prints, PrintSelection $selections)
    {
        return $this->selected($request, FinanceScope::AS_FINANCE, $token, $prints, $selections);
    }

    public function auditSelected(Request $request, string $token, PvPrintService $prints, PrintSelection $selections)
    {
        return $this->selected($request, FinanceScope::AS_AUDIT, $token, $prints, $selections);
    }

    protected function selected(Request $request, string $as, string $token, PvPrintService $prints, PrintSelection $selections)
    {
        $this->door($request, $as);
        $ids = $selections->take($request->user(), $as, $token);
        abort_if($ids === null, 404, 'This print link has expired. Tick the vouchers and prepare the print again.');

        return $this->serve($request, $as, $prints, fn () => $prints->vouchers($request->user(), $as, $ids));
    }

    protected function door(Request $request, string $as): void
    {
        abort_unless(config('gwl.finance_audit_enabled'), 404);
        $this->enforceModule($request, $as === FinanceScope::AS_AUDIT ? Permission::MODULE_AUDIT : Permission::MODULE_FINANCE);
    }

    /** @param  callable(): array<string, mixed>  $build */
    protected function serve(Request $request, string $as, PvPrintService $prints, callable $build): Response|RedirectResponse
    {
        $this->door($request, $as);

        try {
            $document = $build();
        } catch (PrintLimitException $exception) {
            return redirect()->back(fallback: route($as === FinanceScope::AS_AUDIT ? 'audit.home' : 'finance.home'))->with('error', $exception->getMessage());
        }

        $pdf = $prints->pdf($document);
        $prints->record($request->user(), $document);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$prints->filename($document).'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
