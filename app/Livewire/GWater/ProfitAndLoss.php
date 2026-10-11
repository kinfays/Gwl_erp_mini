<?php

namespace App\Livewire\GWater;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\GWater\Concerns\ScopesGwaterByActor;
use App\Services\GWater\GwaterProfitService;
use Illuminate\Support\Carbon;
use Livewire\Component;

/**
 * The profit and loss of the location on screen: month, quarter or a custom range, per product and combined, with the trip-cost
 * table and a chart. It shows what GwaterProfitService returns and works nothing out itself. Needs gwater.view_reports.
 */
class ProfitAndLoss extends Component
{
    use EnforcesModuleAccess;
    use ScopesGwaterByActor;

    public string $preset = 'this_month';

    public string $from = '';

    public string $to = '';

    /** '' = every product, else a product code. */
    public string $product = '';

    public function mount(): void
    {
        $this->enforceGwater();
        $this->guard('gwater.view_reports');

        [$from, $to] = GwaterProfitService::preset('this_month');
        $this->from = $from->toDateString();
        $this->to = $to->toDateString();
    }

    public function updated(string $property): void
    {
        if ($property === 'preset' && $this->preset !== 'custom') {
            [$from, $to] = GwaterProfitService::preset($this->preset);
            $this->from = $from->toDateString();
            $this->to = $to->toDateString();
        }

        if (in_array($property, ['from', 'to'], true)) {
            $this->preset = 'custom';
        }

        if (in_array($property, ['preset', 'from', 'to', 'locationId'], true) && ($report = $this->loadReport())) {
            $this->dispatch('gwater-pnl-updated', charts: ['months' => $report['months']]);
        }
    }

    /** @return array<string, mixed>|null */
    protected function loadReport(): ?array
    {
        $location = $this->workingLocation();

        if (! $location) {
            return null;
        }

        try {
            Carbon::parse($this->from);
            Carbon::parse($this->to);
        } catch (\Throwable) {
            return null; // a half-typed date
        }

        return app(GwaterProfitService::class)->report($location, $this->from, $this->to, $this->actor());
    }

    public function render()
    {
        $report = $this->loadReport();
        $query = array_filter(['from' => $this->from, 'to' => $this->to, 'district' => $this->isSuperAdmin() ? $this->locationId : null]);
        $can = fn (string $permission) => $this->visibility()->can($this->actor(), $permission);

        return view('livewire.gwater.profit-and-loss', [
            'report' => $report,
            'exportQuery' => $query,
            'canExport' => $can('gwater.export') && $can('gwater.view_reports'),
            'products' => $report ? collect($report['products'])->filter(fn ($line, $code) => $this->product === '' || $this->product === $code) : collect(),
            'productChoices' => $report ? collect($report['products'])->map(fn ($line) => $line['name']) : collect(),
        ] + $this->locationView());
    }
}
