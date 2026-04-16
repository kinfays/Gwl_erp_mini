<?php

namespace Database\Seeders;

use App\Models\Holiday;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class HolidaySeeder extends Seeder
{
    public function run(): void
    {
        $year = (int) now()->format('Y');

        $holidays = [
            [
                'holiday_name' => "New Year's Day",
                'holiday_date' => $this->shiftWeekendHoliday(Carbon::create($year, 1, 1)),
            ],
            [
                'holiday_name' => 'Constitution Day',
                'holiday_date' => $this->shiftWeekendHoliday(Carbon::create($year, 1, 7)),
            ],
            [
                'holiday_name' => 'Independence Day',
                'holiday_date' => $this->shiftWeekendHoliday(Carbon::create($year, 3, 6)),
            ],
            [
                'holiday_name' => 'Good Friday',
                'holiday_date' => $this->easterSunday($year)->copy()->subDays(2)->toDateString(),
            ],
            [
                'holiday_name' => 'Easter Monday',
                'holiday_date' => $this->easterSunday($year)->copy()->addDay()->toDateString(),
            ],
            [
                'holiday_name' => 'May Day',
                'holiday_date' => $this->shiftWeekendHoliday(Carbon::create($year, 5, 1)),
            ],
            [
                'holiday_name' => 'Eid al-Fitr Day 1',
                'holiday_date' => $this->shiftWeekendHoliday($this->approximateEidAlFitr($year)),
            ],
            [
                'holiday_name' => 'Eid al-Fitr Day 2',
                'holiday_date' => $this->shiftWeekendHoliday($this->approximateEidAlFitr($year)->copy()->addDay()),
            ],
            [
                'holiday_name' => 'Africa Day',
                'holiday_date' => $this->shiftWeekendHoliday(Carbon::create($year, 5, 25)),
            ],
            [
                'holiday_name' => 'Eid al-Adha',
                'holiday_date' => $this->shiftWeekendHoliday($this->approximateEidAlAdha($year)),
            ],
            [
                'holiday_name' => 'Founders Day',
                'holiday_date' => $this->shiftWeekendHoliday(Carbon::create($year, 8, 4)),
            ],
            [
                'holiday_name' => 'Kwame Nkrumah Day',
                'holiday_date' => $this->shiftWeekendHoliday(Carbon::create($year, 9, 21)),
            ],
            [
                'holiday_name' => 'Farmers Day',
                'holiday_date' => $this->firstFridayOfDecember($year),
            ],
            [
                'holiday_name' => 'Christmas Day',
                'holiday_date' => $this->shiftWeekendHoliday(Carbon::create($year, 12, 25)),
            ],
            [
                'holiday_name' => 'Boxing Day',
                'holiday_date' => $this->shiftWeekendHoliday(Carbon::create($year, 12, 26)),
            ],
        ];

        foreach ($holidays as $holiday) {
            Holiday::query()->updateOrCreate(
                ['holiday_name' => $holiday['holiday_name']],
                ['holiday_date' => $holiday['holiday_date']]
            );
        }
    }

    protected function shiftWeekendHoliday(Carbon $date): string
    {
        if ($date->isSaturday()) {
            return $date->copy()->addDays(2)->toDateString();
        }

        if ($date->isSunday()) {
            return $date->copy()->addDay()->toDateString();
        }

        return $date->toDateString();
    }

    protected function firstFridayOfDecember(int $year): string
    {
        $date = Carbon::create($year, 12, 1);

        while (! $date->isFriday()) {
            $date->addDay();
        }

        return $date->toDateString();
    }

    protected function easterSunday(int $year): Carbon
    {
        return Carbon::createFromTimestamp(easter_date($year))->startOfDay();
    }

    protected function approximateEidAlFitr(int $year): Carbon
    {
        $known = Carbon::create(2025, 3, 31);
        $shiftDays = (int) round(($year - 2025) * 10.875);

        return $known->copy()->subDays($shiftDays);
    }

    protected function approximateEidAlAdha(int $year): Carbon
    {
        $known = Carbon::create(2025, 6, 6);
        $shiftDays = (int) round(($year - 2025) * 10.875);

        return $known->copy()->subDays($shiftDays);
    }
}