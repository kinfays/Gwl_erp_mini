<?php

namespace App\Livewire\Commercial\Concerns;

use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

/**
 * The date-range control of the Billing screen (Phase 8a) for the Summary and Home screens (Phase 8f): a whole-month shortcut and two typed dates
 * (YYYY-MM-DD, or a month YYYY-MM). Empty = no range: the screen is exactly what it was. What a range means is decided by the period model
 * (CommercialPeriods), in the services, never here.
 */
trait UsesDateRange
{
    #[Url(as: 'from')]
    public string $dateFrom = '';

    #[Url(as: 'to')]
    public string $dateTo = '';

    /** The month shortcut: picking one sets the two dates to that whole month. */
    public string $preset = '';

    public function updatedPreset(string $value): void
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
            $this->dateFrom = $value.'-01';
            $this->dateTo = Carbon::parse($value.'-01')->endOfMonth()->toDateString();
        } else {
            $this->dateFrom = '';
            $this->dateTo = '';
        }
    }

    public function clearDates(): void
    {
        $this->reset('dateFrom', 'dateTo', 'preset');
    }

    /** @return array<string, string> the last 12 months, newest first */
    protected function monthPresets(): array
    {
        $out = [];

        for ($month = now()->startOfMonth(); count($out) < 12; $month = $month->copy()->subMonthNoOverflow()) {
            $out[$month->format('Y-m')] = $month->format('M Y');
        }

        return $out;
    }
}
