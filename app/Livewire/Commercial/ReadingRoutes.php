<?php

namespace App\Livewire\Commercial;

use App\Livewire\Commercial\Concerns\ScopesCommercialByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\Permission;
use App\Services\Commercial\CommercialReportData;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The reading report BY ROUTE AND CCA (Phase 8c, design 8.36; analyses R1 to R8). A thin shell: every figure comes from
 * CommercialReportData::routeReading(), the same call the Excel / PDF export makes, so the page and the file cannot disagree. The date
 * range is the Billing one (CommercialPeriods): any dates, the uploads inside the range that do not overlap are added up.
 */
class ReadingRoutes extends Component
{
    use EnforcesModuleAccess;
    use ScopesCommercialByActor;

    public const TABS = [
        'overview' => 'Overview',
        'ccas' => 'CCAs',
        'routes' => 'Routes',
        'checks' => 'Checks',
    ];

    #[Url(as: 'tab')]
    public string $tab = 'overview';

    /** Region id; only honoured for a viewer who has uploads in more than one region. */
    #[Url]
    public string $region = '';

    /** District id (the district of the routes). */
    #[Url]
    public string $district = '';

    /** An exact date range (YYYY-MM-DD, or a month YYYY-MM); empty = the latest upload. */
    #[Url(as: 'from')]
    public string $dateFrom = '';

    #[Url(as: 'to')]
    public string $dateTo = '';

    /** The month shortcut: picking one sets the two dates to that whole month. */
    public string $preset = '';

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_COMMERCIAL);
        $this->guardCommercialPermission('commercial.view_reading');
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
        $this->reset('district', 'dateFrom', 'dateTo', 'preset');
    }

    protected function activeTab(): string
    {
        return array_key_exists($this->tab, self::TABS) ? $this->tab : 'overview';
    }

    public function render(CommercialReportData $data)
    {
        $report = $data->routeReading(
            ctype_digit($this->region) ? (int) $this->region : null,
            ctype_digit($this->district) ? (int) $this->district : null,
            $this->dateFrom,
            $this->dateTo,
        );

        return view('livewire.commercial.reading-routes', [
            'r' => $report,
            'tabs' => self::TABS,
            'activeTab' => $this->activeTab(),
            'canExport' => $this->actorCan('commercial.export_reports'),
            'canUpload' => $this->actorCan('commercial.upload_reports'),
            'exportQuery' => array_filter(['region' => $report['region_id'], 'district' => $report['district_id'], 'from' => $report['range']['from'] ?? null, 'to' => $report['range']['to'] ?? null]),
        ]);
    }
}
