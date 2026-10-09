<?php

namespace App\Livewire\Commercial\Customers;

use App\Livewire\Commercial\Concerns\ScopesCommercialByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\CommercialCustomerCategory;
use App\Models\Permission;
use App\Services\Commercial\Customers\CustomerAnalyticsService;
use App\Services\Commercial\Customers\CustomerImportException;
use App\Services\Commercial\Customers\CustomerListService;
use App\Services\Commercial\Customers\CustomerSnapshots;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Drill-down customer lists and the customer search. Keyset pages (newer / older), never a count over the whole table.
 * Region scope is applied inside the list service from the actor, so an id typed into the URL cannot reach another region.
 * Names, addresses and (masked) phone numbers appear only for holders of commercial.view_customer_details.
 */
class Lists extends Component
{
    use EnforcesModuleAccess;
    use ScopesCommercialByActor;

    #[Url]
    public string $district = '';

    #[Url]
    public string $route = '';

    #[Url]
    public string $group = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $meter = '';

    #[Url]
    public string $bucket = '';

    #[Url]
    public string $sort = 'route';

    #[Url]
    public string $issue = '';

    #[Url]
    public bool $missing = false;

    /**
     * Search text and kind (account / meter / phone / email / name). Deliberately NOT bound to the URL: what is typed here can be
     * a phone number, an e-mail or a name, and personal data does not belong in an address (browser history, server logs).
     */
    public string $search = '';

    public string $searchType = 'account';

    /** The keyset cursor of the page being shown, and the cursors of the pages before it. */
    public ?array $after = null;

    /** @var list<array<int, int>|null> */
    public array $before = [];

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_COMMERCIAL);
        $this->guardCustomerList();
        $this->guardCommercialPermission('commercial.view_customer_analytics');
    }

    public function updated(string $property): void
    {
        if ($property !== 'after' && $property !== 'before') {
            $this->after = null;
            $this->before = [];
        }

        if ($property === 'district') {
            $this->route = '';
        }
    }

    public function next(?array $cursor): void
    {
        if ($cursor === null) {
            return;
        }

        $this->before[] = $this->after;
        $this->after = array_map('intval', $cursor);
    }

    public function previous(): void
    {
        $this->after = $this->before === [] ? null : array_pop($this->before);
    }

    public function clearSearch(): void
    {
        $this->reset('search');
        $this->after = null;
        $this->before = [];
    }

    public function render(CustomerListService $lists, CustomerSnapshots $snapshots)
    {
        $restriction = $this->customerRestriction();
        $details = $this->customerDetails();
        $districts = $snapshots->current($restriction);
        $districtNames = DB::table('districts')->whereIn('id', $districts->keys()->all())->orderBy('district_name')->pluck('district_name', 'id');
        $districtId = ctype_digit($this->district) && $districts->has((int) $this->district) ? (int) $this->district : null;
        $searchTypes = ['account' => 'Account number', 'meter' => 'Meter number'] + ($details ? ['phone' => 'Mobile number', 'email' => 'E-mail', 'name' => 'Name starts with'] : []);
        $type = array_key_exists($this->searchType, $searchTypes) ? $this->searchType : 'account';
        $searching = trim($this->search) !== '';
        $issue = $this->issue !== '' && array_key_exists($this->issue, CustomerAnalyticsService::QUALITY_LABELS) ? $this->issue : null;
        $missing = $this->missing;

        // "Not in the latest file" is a state of the account, not a stored list.
        if ($issue === 'not_in_file') {
            $issue = null;
            $missing = true;
        }

        $page = null;
        $error = null;

        if ($searching || $districtId) {
            try {
                $page = $lists->page([
                    'restriction' => $restriction, 'details' => $details, 'size' => (int) config('gwl.commercial_customer_page_size', 50),
                    'district_id' => $searching ? null : $districtId, 'route_id' => ctype_digit($this->route) && $districtId ? (int) $this->route : null,
                    'group' => array_key_exists($this->group, CommercialCustomerCategory::GROUPS) ? $this->group : null,
                    'status_id' => ctype_digit($this->status) ? (int) $this->status : null, 'meter_status_id' => ctype_digit($this->meter) ? (int) $this->meter : null,
                    'bucket' => $this->bucket !== '' && ctype_digit($this->bucket) ? (int) $this->bucket : null,
                    'sort' => $this->sort, 'after' => $this->after, 'issue' => $searching ? null : $issue, 'missing' => $missing, 'batch_id' => $districtId ? $districts[$districtId]->id : null,
                    'as_of' => $districtId ? $districts[$districtId]->as_of_date->toDateString() : now()->toDateString(),
                    'search' => $searching ? ['type' => $type, 'value' => $this->search] : null,
                ]);
            } catch (CustomerImportException $exception) {
                $error = $exception->getMessage();
            }
        }

        return view('livewire.commercial.customers.lists', [
            'page' => $page,
            'error' => $error,
            'districts' => $districtNames,
            'districtId' => $districtId,
            'routes' => $districtId ? DB::table('commercial_routes')->where('district_id', $districtId)->orderBy('name')->pluck('name', 'id') : collect(),
            'groups' => CommercialCustomerCategory::GROUPS,
            'statuses' => DB::table('commercial_customer_statuses')->orderBy('code')->get(['id', 'code', 'label', 'meaning_confirmed']),
            'meters' => DB::table('commercial_meter_statuses')->orderBy('code')->get(['id', 'code', 'label']),
            'buckets' => CustomerAnalyticsService::BUCKET_LABELS,
            'sorts' => CustomerListService::SORTS,
            'issues' => CustomerAnalyticsService::QUALITY_LABELS,
            'searchTypes' => $searchTypes,
            'activeSearchType' => $type,
            'searching' => $searching,
            'activeIssue' => $issue,
            'issueCap' => \App\Services\Commercial\Customers\CustomerRollupService::ISSUE_LIST_CAP,
            'showMissing' => $missing,
            'details' => $details,
            'canExport' => $this->actorCan('commercial.export_reports'),
            'hasPrevious' => $this->after !== null,
            'asOf' => $districtId ? $districts[$districtId]->as_of_date : null,
        ]);
    }
}
