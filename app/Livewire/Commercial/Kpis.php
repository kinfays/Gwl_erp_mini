<?php

namespace App\Livewire\Commercial;

use App\Livewire\Commercial\Concerns\ScopesCommercialByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\Permission;
use App\Services\Commercial\CommercialKpiService;
use App\Services\Commercial\CommercialReportData;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The district KPI table (sales, billing, collection against the yearly district targets, with efficiency, year to date and charts) and the arrears table, for the
 * last few months of billing. The figures are CommercialReportData::kpis(), the same call the Excel / PDF export makes, so the file
 * can never disagree with the page. A viewer only ever gets the regions they may see.
 */
class Kpis extends Component
{
    use EnforcesModuleAccess;
    use ScopesCommercialByActor;

    #[Url]
    public string $region = '';

    #[Url]
    public string $segment = '';

    #[Url]
    public string $months = '6';

    /** A district id; '' = the whole region and every district. */
    #[Url]
    public string $district = '';

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_COMMERCIAL);
        $this->guardCommercialPermission('commercial.view_billing');
    }

    public function render(CommercialReportData $data)
    {
        $this->guardCommercialPermission('commercial.view_billing');

        $months = in_array($this->months, ['2', '3', '4', '6', '12'], true) ? (int) $this->months : 6;
        $kpis = $data->kpis(ctype_digit($this->region) ? (int) $this->region : null, $this->segment !== '' ? $this->segment : null, $months, ctype_digit($this->district) ? (int) $this->district : null);

        $charts = $kpis['empty'] ? null : app(CommercialKpiService::class)->charts($kpis, ctype_digit($this->district) ? (int) $this->district : null);

        return view('livewire.commercial.kpis', [
            'kpis' => $kpis,
            'charts' => $charts,
            'canExport' => $this->actorCan('commercial.export_reports'),
            'canSetTargets' => $this->actorCan('commercial.manage_settings'),
            'monthsChosen' => $months,
        ]);
    }
}
