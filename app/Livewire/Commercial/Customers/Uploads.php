<?php

namespace App\Livewire\Commercial\Customers;

use App\Livewire\Commercial\Concerns\ScopesCommercialByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\CommercialCustomerBatch;
use App\Models\CommercialCustomerUpload;
use App\Models\Permission;
use App\Services\Commercial\Customers\CustomerUploadReminders;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Customer-list uploads: the form (a plain post to the controller, because these files are far too big for Livewire's temporary
 * uploads), the overdue districts and the history, ONE ROW PER UPLOAD (Phase 8b: a file may hold several districts) with its districts
 * expandable. The list refreshes itself while any upload is still being worked on.
 */
class Uploads extends Component
{
    use EnforcesModuleAccess;
    use ScopesCommercialByActor;
    use WithPagination;

    #[Url]
    public string $status = '';

    /** @var array<int, bool> upload id => its districts are shown */
    public array $open = [];

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_COMMERCIAL);
        $this->guardCustomerList();
        $this->guardCommercialPermission('commercial.upload_reports', 'commercial.resolve_matches', 'commercial.void_batches');
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function toggle(int $id): void
    {
        $this->open[$id] = ! ($this->open[$id] ?? false);
    }

    /** Uploads the viewer may see: their region's, plus their own still parked before a region was known; a district user: those with a batch in their district, and their own. */
    protected function scopedUploads()
    {
        $restriction = $this->customerRestriction();
        $districtIds = $this->customerDistrictIds();
        $query = CommercialCustomerUpload::query();

        if ($districtIds !== null) {
            return $query->where(fn ($w) => ($districtIds === [] ? $w->whereRaw('1 = 0') : $w->whereIn('id', fn ($in) => $in->from('commercial_customer_batches')->select('upload_id')->whereIn('district_id', $districtIds)))
                ->orWhere('imported_by', auth()->id()));
        }

        if ($restriction === null) {
            return $query;
        }

        return $query->where(fn ($w) => ($restriction === 0 ? $w->whereRaw('1 = 0') : $w->where('region_id', $restriction))
            ->orWhere(fn ($own) => $own->whereNull('region_id')->where('imported_by', auth()->id())));
    }

    public function render(CustomerUploadReminders $reminders)
    {
        $districtIds = $this->customerDistrictIds();

        $uploads = $this->scopedUploads()->with(['region', 'importer'])
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->orderByDesc('id')->paginate(12);

        // the batches of the uploads on this page; a district user is given their own district's only (and a parked batch of their own)
        $children = CommercialCustomerBatch::query()->with('district')->whereIn('upload_id', $uploads->pluck('id')->all() ?: [0])
            ->when($districtIds !== null, fn ($q) => $q->where(fn ($w) => ($districtIds === [] ? $w->whereRaw('1 = 0') : $w->whereIn('district_id', $districtIds))
                ->orWhere(fn ($own) => $own->whereNull('district_id')->where('imported_by', auth()->id()))))
            ->orderBy('id')->get();

        return view('livewire.commercial.customers.uploads', [
            'uploads' => $uploads,
            'children' => $children->groupBy('upload_id'),
            // the batches on this page, flat (every screen that lists batches reads them from here)
            'batches' => $children,
            'statuses' => CommercialCustomerUpload::STATUSES,
            'working' => $uploads->contains(fn (CommercialCustomerUpload $u) => $u->isWorking()) || $children->contains(fn (CommercialCustomerBatch $b) => $b->isWorking() || $b->phase === 'voiding'),
            // the overdue list names districts across the region: a district user does not have it
            'overdue' => $this->actorDistrictLimited() ? [] : $reminders->overdue(now(), $this->customerRestriction()),
            'canUpload' => $this->actorCan('commercial.upload_reports'),
            'maxMb' => (int) config('gwl.commercial_customer_import_max_mb', 200),
            'canLookups' => $this->actorCan('commercial.manage_settings'),
        ]);
    }
}
