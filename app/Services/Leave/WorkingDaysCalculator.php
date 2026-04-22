<?php

namespace App\Services\Leave;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

class WorkingDaysCalculator
{
    /**
     * Calculate working days between two dates (inclusive),
     * excluding weekends and public holidays with Ghana-specific rules.
     */
    public function calculate(Carbon $startDate, Carbon $endDate): int
    {
        if ($endDate->lessThan($startDate)) {
            return 0;
        }

        // Normalize dates
        $startDate = $startDate->copy()->startOfDay();
        $endDate   = $endDate->copy()->startOfDay();

        // Fetch holidays in range (+ buffer for spillovers)
        $holidays = DB::table('holidays')
            ->whereBetween('date', [
                $startDate->copy()->subDays(1),
                $endDate->copy()->addDays(3),
            ])
            ->get(['date', 'name'])
            ->map(fn ($h) => [
                'date' => Carbon::parse($h->date)->toDateString(),
                'name' => strtolower($h->name),
            ]);

        // Build exclusion date set
        $excludedDates = collect();

        foreach ($holidays as $holiday) {
            $holidayDate = Carbon::parse($holiday['date']);
            $excludedDates->push($holidayDate->toDateString());

            // Skip extension rules for Eid holidays
            if ($this->isEidHoliday($holiday['name'])) {
                continue;
            }

            // Weekend spillover → next Monday
            if ($holidayDate->isSaturday() || $holidayDate->isSunday()) {
                $excludedDates->push(
                    $holidayDate->copy()->next(Carbon::MONDAY)->toDateString()
                );
            }

            // Tue/Wed/Thu → extend to Friday
            if (
                $holidayDate->isTuesday() ||
                $holidayDate->isWednesday() ||
                $holidayDate->isThursday()
            ) {
                $excludedDates->push(
                    $holidayDate->copy()->next(Carbon::FRIDAY)->toDateString()
                );
            }
        }

        $excludedDates = $excludedDates->unique();

        // Iterate through date range
        $workingDays = 0;

        foreach (CarbonPeriod::create($startDate, $endDate) as $date) {
            if ($date->isWeekend()) {
                continue;
            }

            if ($excludedDates->contains($date->toDateString())) {
                continue;
            }

            $workingDays++;
        }

        return $workingDays;
    }

    /**
     * Determine if a holiday is an Eid festival.
     */
    protected function isEidHoliday(string $holidayName): bool
    {
        return str_contains($holidayName, 'eid');
    }
}