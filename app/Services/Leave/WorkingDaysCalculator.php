<?php

namespace App\Services\Leave;

use App\Models\Holiday;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

class WorkingDaysCalculator
{
    /**
     * Calculate working days between two dates inclusive.
     */
    public function workingDays(Carbon $start, Carbon $end): int
    {
        $start = $start->copy()->startOfDay();
        $end = $end->copy()->startOfDay();

        if ($end->lt($start)) {
            [$start, $end] = [$end, $start];
        }

        $excluded = $this->excludedDatesBetween($start, $end);

        $count = 0;

        foreach (CarbonPeriod::create($start, $end) as $date) {
            // Weekend?
            if ($date->isSaturday() || $date->isSunday()) {
                continue;
            }

            // Holiday/observed holiday?
            if (isset($excluded[$date->toDateString()])) {
                continue;
            }

            $count++;
        }

        return $count;
    }

    /**
     * Build a set of excluded dates (holidays + observed holidays) within range.
     */
    public function excludedDatesBetween(Carbon $start, Carbon $end): array
    {
        // Pull holidays near the range because observed dates might spill (Mon/Fri)
        $queryStart = $start->copy()->subDays(7);
        $queryEnd = $end->copy()->addDays(7);

        $holidays = Holiday::query()
            ->whereBetween('holiday_date', [$queryStart->toDateString(), $queryEnd->toDateString()])
            ->get(['holiday_name', 'holiday_date']);

        $excluded = [];

        foreach ($holidays as $holiday) {
            $name = (string) $holiday->holiday_name;
            $date = Carbon::parse($holiday->holiday_date)->startOfDay();

            // Determine observed date based on your rules
            $observed = $this->observedHolidayDate($name, $date);

            $excluded[$observed->toDateString()] = true;

            // Special Eid clarification:
            // Eid is stored as separate holiday rows (2 rows for Eid al-Fitr, 1 for Eid ul-Adha).
            // Each row is processed individually; weekend shift applies to each.
        }

        return $excluded;
    }

    /**
     * Apply your exact holiday shifting rules:
     * - If Sat/Sun => observed next Monday
     * - If Tue/Wed/Thu => observed Friday (except Eid holidays remain on the date)
     */
    public function observedHolidayDate(string $holidayName, Carbon $holidayDate): Carbon
    {
        $isEid = str_contains(mb_strtolower($holidayName), 'eid');

        // Weekend shift: Saturday/Sunday => Monday
        if ($holidayDate->isSaturday()) {
            return $holidayDate->copy()->addDays(2); // Monday
        }

        if ($holidayDate->isSunday()) {
            return $holidayDate->copy()->addDay(); // Monday
        }

        // Tue/Wed/Thu => move to Friday (except Eid)
        if (! $isEid && ($holidayDate->isTuesday() || $holidayDate->isWednesday() || $holidayDate->isThursday())) {
            // Move to Friday of the same week
            return $holidayDate->copy()->next(Carbon::FRIDAY);
        }

        // Otherwise unchanged
        return $holidayDate->copy();
    }
}