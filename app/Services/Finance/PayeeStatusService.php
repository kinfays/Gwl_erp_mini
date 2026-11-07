<?php

namespace App\Services\Finance;

use App\Models\Employee;
use App\Models\FinPaymentVoucher;

/**
 * The "payee left service" flag Audit (and Finance) see on a voucher paid to an EMPLOYEE. ONE test, here, never in Blade:
 *
 *  - the person is no longer in service (employees.is_active = 0), and the exit was not a Transfer (a transfer is a move, not
 *    leaving; the same rule as FinanceScope::employees() and Staff's exit analytics); and
 *  - they left ON OR BEFORE the PV date (`deactivated_at <= pv_date`): a payment to someone already gone, the one Audit must look
 *    at; otherwise they left AFTER the voucher was dated: a softer "has since left" note, so Audit knows to check the payee is still
 *    due the money. A record with no exit date (older records) falls in the second group, since there is nothing to compare.
 *
 * A retiree, district or supplier payee is never flagged (a retiree is MEANT to have left). The flag is information: it blocks
 * nothing and writes nothing. It is not put in a notice or an audit row.
 */
class PayeeStatusService
{
    public const LEFT_BEFORE_PV = 'left_before_pv';

    public const LEFT_SINCE = 'left_since';

    /**
     * @return array{code: string, left_on: \Illuminate\Support\Carbon|null, reason: string|null, message: string}|null
     */
    public function warningFor(FinPaymentVoucher $pv): ?array
    {
        if ($pv->payee_kind !== FinPaymentVoucher::PAYEE_EMPLOYEE || ! $pv->payee_employee_id) {
            return null;
        }

        return $this->evaluate($pv, Employee::query()->find($pv->payee_employee_id));
    }

    /**
     * The same test for a page of vouchers with ONE query.
     *
     * @param  iterable<FinPaymentVoucher>  $vouchers
     * @return array<int, array{code: string, left_on: \Illuminate\Support\Carbon|null, reason: string|null, message: string}> by voucher id; only the flagged ones
     */
    public function warningsFor(iterable $vouchers): array
    {
        $candidates = collect($vouchers)->filter(fn (FinPaymentVoucher $pv) => $pv->payee_kind === FinPaymentVoucher::PAYEE_EMPLOYEE && $pv->payee_employee_id);

        if ($candidates->isEmpty()) {
            return [];
        }

        $employees = Employee::query()->whereIn('id', $candidates->pluck('payee_employee_id')->unique()->all())->get()->keyBy('id');
        $flags = [];

        foreach ($candidates as $pv) {
            $warning = $this->evaluate($pv, $employees->get($pv->payee_employee_id));

            if ($warning) {
                $flags[$pv->id] = $warning;
            }
        }

        return $flags;
    }

    /** The one test. */
    public function evaluate(FinPaymentVoucher $pv, ?Employee $employee): ?array
    {
        if ($pv->payee_kind !== FinPaymentVoucher::PAYEE_EMPLOYEE || ! $employee || $employee->is_active) {
            return null;
        }

        if ($employee->deactivation_reason === Employee::EXIT_REASON_TRANSFER) {
            return null;
        }

        $leftOn = $employee->deactivated_at;
        $reason = $employee->deactivation_reason ? (Employee::DEACTIVATION_REASONS[$employee->deactivation_reason] ?? null) : null;

        if ($leftOn && $leftOn->toDateString() <= $pv->pv_date->toDateString()) {
            return [
                'code' => self::LEFT_BEFORE_PV,
                'left_on' => $leftOn,
                'reason' => $reason,
                'message' => 'The payee left service on '.$leftOn->format('d M Y').', on or before the PV date ('.$pv->pv_date->format('d M Y').').'.($reason ? ' Reason recorded: '.$reason.'.' : ''),
            ];
        }

        return [
            'code' => self::LEFT_SINCE,
            'left_on' => $leftOn,
            'reason' => $reason,
            'message' => 'The payee has since left service'.($leftOn ? ' (on '.$leftOn->format('d M Y').')' : '').'. Check they are still due this payment.'.($reason ? ' Reason recorded: '.$reason.'.' : ''),
        ];
    }
}
