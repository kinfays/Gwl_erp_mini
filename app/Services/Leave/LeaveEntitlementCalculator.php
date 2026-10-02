<?php

namespace App\Services\Leave;

use App\Enums\StaffGrade;
use App\Models\CompulsoryLeavePeriod;
use App\Models\Employee;
use Carbon\Carbon;

/**
 * Annual leave entitlement: what a member of staff is entitled to in a leave year, from their grade and years of
 * service, and how much of it is compulsory leave. Nothing is read from or written to the database except — only when
 * the caller doesn't pass it — the year's compulsory days; pass `$periodDays` to keep a call pure.
 *
 *   gross       by grade (config gwl.leave_annual_days):
 *                 Junior Gd. L1-3, under 10 years' service  26   (the threshold is gwl.leave_junior_lower_tenure_years)
 *                 Junior Gd. L1-3, 10 years or more         31
 *                 Junior Gd. L4-6                           31
 *                 Snr. Gd. and Mgt. Gd., every level        36
 *                 no grade yet                              31   (the flat entitlement from before grades)
 *                 Charwoman (contract)                       0   not eligible for leave
 *   compulsory  the year's compulsory days, for graded Head Office and regional office staff only (never district staff
 *               or contract staff), and never more than the gross entitlement. Staff with no grade yet are left exactly
 *               as they were — flat gross, no automatic compulsory deduction — until they are graded
 *   net         gross - compulsory: the days actually available to take
 *
 * Tenure is whole years from the hire date (employees.date_joined) to 1 January of the leave year. With no hire date
 * it can't be decided, so a lower-grade junior keeps the longer-service figure rather than losing days over missing data.
 */
class LeaveEntitlementCalculator
{
    /** @param  array<string, mixed>|null  $rules  gwl config overrides; null reads config('gwl') */
    public function __construct(protected ?array $rules = null) {}

    /** Contract staff (Charwoman grade, or the Contract / Charwoman category on a record with no grade) have no leave. */
    public function isEligible(Employee $employee): bool
    {
        $grade = $employee->staffGrade();

        if ($grade !== null) {
            return $grade->isLeaveEligible();
        }

        return ! in_array($employee->category, [StaffGrade::CATEGORY_CONTRACT, 'Charwoman'], true);
    }

    /** Whole years of service at 1 January of $year; null when there is no hire date; 0 when hired on or after it. */
    public function tenureYears(Employee $employee, int $year): ?int
    {
        if (! $employee->date_joined) {
            return null;
        }

        $hired = Carbon::parse($employee->date_joined)->startOfDay();
        $reference = Carbon::create($year, 1, 1)->startOfDay();

        if ($hired->gte($reference)) {
            return 0;
        }

        $years = $reference->year - $hired->year;

        // Not yet had this year's anniversary by 1 January: one year fewer.
        if ($hired->copy()->addYearsNoOverflow($years)->gt($reference)) {
            $years--;
        }

        return max(0, $years);
    }

    public function grossEntitlement(Employee $employee, int $year): int
    {
        if (! $this->isEligible($employee)) {
            return 0;
        }

        $days = $this->days();
        $grade = $employee->staffGrade();

        if ($grade === null) {
            return $days['ungraded'];
        }

        if ($grade->isJuniorLower()) {
            $tenure = $this->tenureYears($employee, $year);
            $threshold = (int) $this->rule('leave_junior_lower_tenure_years', 10);

            return $tenure !== null && $tenure < $threshold
                ? $days['junior_lower_short_tenure']
                : $days['junior_lower_long_tenure'];
        }

        return $grade->isJunior() ? $days['junior_upper'] : $days['senior_and_management'];
    }

    /**
     * Does compulsory leave apply to this person at all? Graded Head Office or regional office staff who are eligible for
     * leave. District staff, contract staff and staff with no grade yet (whose entitlement stays as it was) are outside it.
     */
    public function appliesCompulsory(Employee $employee): bool
    {
        return $employee->staffGrade() !== null
            && $this->isEligible($employee)
            && in_array($employee->location_type, (array) $this->rule('leave_compulsory_location_types', ['HeadOffice', 'Region']), true);
    }

    /**
     * @param  int|null  $periodDays  the year's compulsory days; null looks them up (CompulsoryLeavePeriod::effectiveDaysFor)
     */
    public function compulsoryDeduction(Employee $employee, int $year, ?int $periodDays = null): int
    {
        if (! $this->appliesCompulsory($employee)) {
            return 0;
        }

        $days = $periodDays ?? CompulsoryLeavePeriod::effectiveDaysFor($year);

        return max(0, min($days, $this->grossEntitlement($employee, $year)));
    }

    public function netEntitlement(Employee $employee, int $year, ?int $periodDays = null): int
    {
        return $this->grossEntitlement($employee, $year) - $this->compulsoryDeduction($employee, $year, $periodDays);
    }

    /** @return array{gross: int, compulsory: int, net: int, tenure_years: int|null, grade: string|null, eligible: bool} */
    public function breakdown(Employee $employee, int $year, ?int $periodDays = null): array
    {
        $gross = $this->grossEntitlement($employee, $year);
        $compulsory = $this->compulsoryDeduction($employee, $year, $periodDays);

        return [
            'gross' => $gross,
            'compulsory' => $compulsory,
            'net' => $gross - $compulsory,
            'tenure_years' => $this->tenureYears($employee, $year),
            'grade' => $employee->grade,
            'eligible' => $this->isEligible($employee),
        ];
    }

    /** @return array{junior_lower_short_tenure: int, junior_lower_long_tenure: int, junior_upper: int, senior_and_management: int, ungraded: int} */
    protected function days(): array
    {
        $configured = (array) $this->rule('leave_annual_days', []);

        return [
            'junior_lower_short_tenure' => (int) ($configured['junior_lower_short_tenure'] ?? 26),
            'junior_lower_long_tenure' => (int) ($configured['junior_lower_long_tenure'] ?? 31),
            'junior_upper' => (int) ($configured['junior_upper'] ?? 31),
            'senior_and_management' => (int) ($configured['senior_and_management'] ?? 36),
            'ungraded' => (int) ($configured['ungraded'] ?? 31),
        ];
    }

    protected function rule(string $key, mixed $default): mixed
    {
        return $this->rules[$key] ?? config('gwl.'.$key, $default);
    }
}
