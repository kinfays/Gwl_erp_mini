<?php

namespace App\Livewire\Assets\Mdm;

use App\Livewire\Assets\Mdm\Concerns\AuthorizesMdm;
use App\Services\Assets\Mdm\MdmDashboardService;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * MDM landing screen: fleet totals, event-intake health, recent enrollments and commands, and the device list.
 * Everything is computed over the devices the viewer's region scope allows.
 */
class DevicesDashboard extends Component
{
    use AuthorizesMdm;
    use WithPagination;

    public const FILTERS = [
        '' => 'All devices',
        'non_compliant' => 'Non-compliant',
        'lost' => 'Lost',
        'needs_review' => 'Needs review',
        'stale' => 'Not reported recently',
    ];

    public string $search = '';

    public string $filter = '';

    public int $perPage = 15;

    public function mount(): void
    {
        $this->bootMdmScreen('assets.mdm_view');
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'filter', 'perPage'], true)) {
            $this->resetPage();
        }
    }

    public function render(MdmDashboardService $dashboard)
    {
        $viewer = $this->mdmUser();
        $filter = array_key_exists($this->filter, self::FILTERS) ? $this->filter : '';
        $staleBefore = now()->subHours(max(1, (int) config('gwl.mdm_stale_report_hours', 24)));

        $devices = $this->mdmGuard()->devices($viewer)
            ->notDeleted()
            ->with(['asset.assignedTo', 'asset.district', 'policy'])
            ->when($this->search !== '', function (Builder $query) {
                $term = '%'.$this->search.'%';

                $query->where(function (Builder $inner) use ($term) {
                    $inner->where('google_device_name', 'like', $term)
                        ->orWhereHas('asset', function (Builder $asset) use ($term) {
                            $asset->where('asset_name', 'like', $term)
                                ->orWhere('serial_number', 'like', $term)
                                ->orWhere('imei', 'like', $term)
                                ->orWhereHas('assignedTo', fn (Builder $employee) => $employee->where('full_name', 'like', $term));
                        });
                });
            })
            ->when($filter === 'non_compliant', fn (Builder $q) => $q->where('policy_compliant', false))
            ->when($filter === 'lost', fn (Builder $q) => $q->where('is_lost', true))
            ->when($filter === 'needs_review', fn (Builder $q) => $q->where('needs_review', true))
            ->when($filter === 'stale', fn (Builder $q) => $dashboard->notReported($q, $staleBefore))
            ->latest('last_status_report_at')
            ->latest('id')
            ->paginate($this->perPage);

        return view('livewire.assets.mdm.devices-dashboard', [
            'summary' => $dashboard->build($viewer),
            'devices' => $devices,
            'filters' => self::FILTERS,
            'canEnroll' => $this->canMdm('assets.mdm_enroll'),
            'staleBefore' => $staleBefore,
        ]);
    }
}
