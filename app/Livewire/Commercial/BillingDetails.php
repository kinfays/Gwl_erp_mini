<?php

namespace App\Livewire\Commercial;

use App\Livewire\Commercial\Concerns\ScopesCommercialByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\Permission;
use App\Services\Commercial\BillingDetails\BillingDetailsReport;
use App\Services\Commercial\CommercialReportData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The Detailed Billing Report analyses B1 to B13 (Phase 8d, design 8.39). A thin shell: every figure comes from
 * CommercialReportData::billingDetails(), the same call the Excel / PDF export makes, so the page and the file cannot disagree. The date range
 * is the Billing one (CommercialPeriods): any dates, the uploads inside the range that do not overlap are added up. The district limit
 * (CommercialScope) is applied where the rows are read, never here.
 */
class BillingDetails extends Component
{
    use EnforcesModuleAccess;
    use ScopesCommercialByActor;

    public const TABS = [
        'overview' => 'Overview (B1)',
        'estimates' => 'Estimates and unbilled (B2, B3)',
        'consumption' => 'Consumption and charges (B4, B5)',
        'money' => 'Money (B6 to B9)',
        'checks' => 'Checks (B10 to B12)',
        'trend' => 'Trend (B13)',
    ];

    #[Url(as: 'tab')]
    public string $tab = 'overview';

    /** Region id; only honoured for a viewer who has uploads in more than one region. */
    #[Url]
    public string $region = '';

    #[Url]
    public string $district = '';

    /** A service category code. */
    #[Url]
    public string $category = '';

    /** A CCA's staff id: narrows B1 to B11 to the routes that CCA owns (only for a viewer who may see individual CCAs). */
    #[Url]
    public string $cca = '';

    /** One route (its canonical route id). */
    #[Url]
    public string $route = '';

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
        $this->guardCommercialPermission('commercial.view_billing');
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
        $this->reset('district', 'category', 'cca', 'route', 'dateFrom', 'dateTo', 'preset');
    }

    protected function activeTab(): string
    {
        return array_key_exists($this->tab, self::TABS) ? $this->tab : 'overview';
    }

    public function render(CommercialReportData $data)
    {
        $report = $data->billingDetails(
            ctype_digit($this->region) ? (int) $this->region : null,
            ctype_digit($this->district) ? (int) $this->district : null,
            $this->category !== '' ? $this->category : null,
            $this->cca !== '' ? $this->cca : null,
            ctype_digit($this->route) ? (int) $this->route : null,
            $this->dateFrom,
            $this->dateTo,
        );

        // an account on a list opens the existing customer view (which re-checks its own scope and permission; names stay behind view_customer_details)
        $links = [];

        if ($report['available'] && config('gwl.commercial_customer_list_enabled') && $this->actorCan('commercial.view_customer_analytics')) {
            $accounts = collect($report['lists'])->flatMap(fn (array $list) => array_column(array_slice($list['rows'], 0, BillingDetailsReport::LIST_ROWS), 'account'))->unique()->values()->all();

            foreach (array_chunk($accounts, 400) as $chunk) {
                foreach (DB::table('commercial_customers')->whereIn('account_no', $chunk)->pluck('id', 'account_no') as $account => $id) {
                    $links[$account] = (int) $id;
                }
            }
        }

        return view('livewire.commercial.billing-details', [
            'r' => $report,
            'tabs' => self::TABS,
            'activeTab' => $this->activeTab(),
            'canExport' => $this->actorCan('commercial.export_reports'),
            'canUpload' => $this->actorCan('commercial.upload_reports'),
            'customerLinks' => $links,
            'exportQuery' => array_filter([
                'region' => $report['region_id'], 'district' => $report['district_id'], 'category' => $report['category'], 'cca' => $report['cca'], 'route' => $report['route_id'],
                'from' => $report['range']['from'] ?? null, 'to' => $report['range']['to'] ?? null,
            ], fn ($value) => $value !== null && $value !== ''),
        ]);
    }
}
