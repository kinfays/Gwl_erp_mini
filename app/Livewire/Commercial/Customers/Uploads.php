<?php

namespace App\Livewire\Commercial\Customers;

use App\Livewire\Commercial\Concerns\ScopesCommercialByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\CommercialCustomerBatch;
use App\Models\Permission;
use App\Services\Commercial\Customers\CustomerUploadReminders;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Customer-list uploads: the form (a plain post to the controller, because these files are far too big for Livewire's
 * temporary uploads), the overdue districts and the batches with their live progress. The list refreshes itself while any
 * batch is still being worked on.
 */
class Uploads extends Component
{
    use EnforcesModuleAccess;
    use ScopesCommercialByActor;
    use WithPagination;

    #[Url]
    public string $status = '';

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

    /** Batches the viewer may see: their region's, plus their own uploads still parked before a region was known. */
    protected function scopedBatches()
    {
        $restriction = $this->customerRestriction();
        $query = CommercialCustomerBatch::query();

        if ($restriction === null) {
            return $query;
        }

        return $query->where(fn ($w) => ($restriction === 0 ? $w->whereRaw('1 = 0') : $w->where('region_id', $restriction))
            ->orWhere(fn ($own) => $own->whereNull('region_id')->where('imported_by', auth()->id())));
    }

    public function render(CustomerUploadReminders $reminders)
    {
        $batches = $this->scopedBatches()->with(['district', 'region', 'importer'])
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->orderByDesc('id')->paginate(12);

        return view('livewire.commercial.customers.uploads', [
            'batches' => $batches,
            'statuses' => CommercialCustomerBatch::STATUSES,
            'working' => $batches->contains(fn (CommercialCustomerBatch $batch) => $batch->isWorking() || $batch->phase === 'voiding'),
            'overdue' => $reminders->overdue(now(), $this->customerRestriction()),
            'canUpload' => $this->actorCan('commercial.upload_reports'),
            'maxMb' => (int) config('gwl.commercial_customer_import_max_mb', 200),
            'canLookups' => $this->actorCan('commercial.manage_settings'),
        ]);
    }
}
