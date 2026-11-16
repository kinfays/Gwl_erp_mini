<?php

namespace App\Livewire\Commercial;

use App\Livewire\Commercial\Concerns\ScopesCommercialByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\Permission;
use App\Services\Commercial\Cross\CrossFileReport;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One of the analyses that need more than one file (Phase 8e, design 8.42): X1 and X3 to X12. A thin shell: every figure comes from
 * CrossFileReport::build(), the same call the Excel / PDF export makes, so the page and the file cannot disagree. Which analysis is a locked
 * property (a browser cannot swap it); the range and the window rule are the filters.
 */
class CrossAnalysis extends Component
{
    use EnforcesModuleAccess;
    use ScopesCommercialByActor;

    #[Locked]
    public string $analysis = 'x1';

    /** Region id; only honoured for a viewer who has uploads in more than one region. */
    #[Url]
    public string $region = '';

    #[Url]
    public string $district = '';

    /** An exact date range (YYYY-MM-DD, or a month YYYY-MM); empty = each part's newest upload. */
    #[Url(as: 'from')]
    public string $dateFrom = '';

    #[Url(as: 'to')]
    public string $dateTo = '';

    /** The window rule: "latest" = each part uses its own newest upload inside the range; "same" = the typed range for every part. */
    #[Url]
    public string $mode = 'latest';

    /** The month shortcut: picking one sets the two dates to that whole month. */
    public string $preset = '';

    public function mount(string $analysis): void
    {
        $this->enforceLivewireModule(Permission::MODULE_COMMERCIAL);
        $this->guardCommercialPermission('commercial.view_reading', 'commercial.view_billing', 'commercial.view_customer_analytics');
        abort_unless(array_key_exists($analysis, CrossFileReport::ANALYSES), 404);
        $this->analysis = $analysis;
    }

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

    public function clearFilters(): void
    {
        $this->reset('district', 'dateFrom', 'dateTo', 'preset', 'mode');
    }

    /** The months the viewer's uploads reach, for the "a whole month" shortcut: the last 12 months up to this one. @return array<string, string> */
    protected function monthOptions(): array
    {
        $out = [];

        for ($month = now()->startOfMonth(); count($out) < 12; $month = $month->copy()->subMonthNoOverflow()) {
            $out[$month->format('Y-m')] = $month->format('M Y');
        }

        return $out;
    }

    public function render(CrossFileReport $report)
    {
        $r = $report->build($this->analysis, [
            'region' => ctype_digit($this->region) ? (int) $this->region : null,
            'district' => ctype_digit($this->district) ? (int) $this->district : null,
            'from' => $this->dateFrom, 'to' => $this->dateTo, 'mode' => $this->mode === 'same' ? 'same' : 'latest',
        ]);

        // Phase 8f (T2): on the district scorecard, the change on the previous comparable period of each report the viewer may see
        $trendBlocks = [];

        if ($this->analysis === 'x7' && $r['region_id'] !== null) {
            foreach (['reading', 'billing', 'customers'] as $kind) {
                $trendBlocks[$kind] = app(\App\Services\Commercial\CommercialReportData::class)->trendBlock($kind, ['region' => $r['region_id'], 'district' => $r['district_id'], 'from' => $this->dateFrom, 'to' => $this->dateTo]);
            }
        }

        return view('livewire.commercial.cross-analysis', [
            'trendBlocks' => $trendBlocks,
            'r' => $r, 'analysis' => $this->analysis, 'monthOptions' => $this->monthOptions(), 'canExport' => $this->actorCan('commercial.export_reports'),
            'exportQuery' => array_filter(['region' => $r['region_id'], 'district' => $r['district_id'], 'from' => $this->dateFrom, 'to' => $this->dateTo, 'mode' => $r['mode'] === 'same' ? 'same' : null], fn ($v) => $v !== null && $v !== ''),
            'routeQuery' => array_filter(['from' => $this->dateFrom, 'to' => $this->dateTo, 'mode' => $r['mode'] === 'same' ? 'same' : null], fn ($v) => $v !== null && $v !== ''),
        ]);
    }
}
