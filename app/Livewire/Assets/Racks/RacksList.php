<?php

namespace App\Livewire\Assets\Racks;

use App\Livewire\Assets\Concerns\ExportsAssetLists;
use App\Livewire\Assets\Concerns\ScopesAssetsByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\Concerns\HasSelectableColumns;
use App\Models\IctRack;
use App\Services\Assets\AssetListColumns;
use App\Services\Assets\AssetListQuery;
use App\Services\Assets\RackService;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Assets > Racks: the list of network cabinets / server racks the person may see (their own region, or every region for
 * Head Office ICT and unscoped roles, exactly like the asset lists), with search, status and district filters in the
 * URL, selectable columns and Excel / PDF export (Phase 8). Create and edit are the full-page RackForm; delete is the
 * confirmation here, judged on the WRITE scope by RackService.
 */
class RacksList extends Component
{
    use EnforcesModuleAccess;
    use ExportsAssetLists;
    use HasSelectableColumns;
    use ScopesAssetsByActor;
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: '')]
    public string $status = '';

    /** Selected district ids; empty means every district the actor may see. */
    #[Url(as: 'districts')]
    public array $districtIds = [];

    public int $perPage = 15;

    /** The rack the delete confirmation is open for. Locked, and resolved again inside the write scope on every call. */
    #[Locked]
    public ?int $deletingRackId = null;

    public function mount(): void
    {
        $this->enforceLivewireModule('assets');

        abort_unless(RackService::mayView($this->actor()), 403, 'You do not have permission to view racks.');
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'status', 'perPage', 'districtIds'], true)) {
            $this->resetPage();
        }
    }

    /** @return array<int, int> the chosen districts the actor may actually see (a hand-edited URL is cut down) */
    protected function selectedDistrictIds(): array
    {
        return $this->permittedDistrictIds($this->districtIds);
    }

    public function toggleDistrict(string $id): void
    {
        $allowed = $this->permittedDistrictIds([$id]);

        if ($allowed === []) {
            return;
        }

        $selected = $this->selectedDistrictIds();

        $this->districtIds = in_array($allowed[0], $selected, true)
            ? array_values(array_diff($selected, [$allowed[0]]))
            : [...$selected, $allowed[0]];
        $this->resetPage();
    }

    public function selectAllDistricts(): void
    {
        $this->districtIds = $this->viewableDistricts()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->resetPage();
    }

    public function clearDistricts(): void
    {
        $this->districtIds = [];
        $this->resetPage();
    }

    public function confirmDelete(int $rackId): void
    {
        $this->authorizeManage();

        $this->resetErrorBag('delete');
        $this->deletingRackId = $this->deletableRack($rackId)->id;
    }

    public function cancelDelete(): void
    {
        $this->deletingRackId = null;
        $this->resetErrorBag('delete');
    }

    public function deleteRack(): void
    {
        $this->authorizeManage();
        abort_unless($this->deletingRackId, 404);

        $rack = $this->deletableRack($this->deletingRackId);

        try {
            // A rack that still holds devices is refused with a ValidationException on `delete`, shown in the dialog.
            app(RackService::class)->delete($rack, $this->actor());
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        $this->dispatch('toast', type: 'success', message: 'Rack deleted.');
        $this->cancelDelete();
        $this->resetPage();
    }

    protected function authorizeManage(): void
    {
        abort_unless(RackService::mayManage($this->actor()), 403, 'You do not have permission to manage racks.');
    }

    /** Resolved through the WRITE scope, so a rack the person can only see answers 404 here as it does for Edit. */
    protected function deletableRack(int $rackId): IctRack
    {
        return $this->scopeAssetsForActor(IctRack::query())->findOrFail($rackId);
    }

    protected function columnManifest(): array
    {
        return AssetListColumns::manifest(AssetListColumns::RACKS);
    }

    /** The list's filters, named as AssetListQuery (and the export links) name them. */
    protected function listFilters(): array
    {
        return [
            'search' => $this->search,
            'status' => in_array($this->status, IctRack::STATUSES, true) ? $this->status : '',
            'districts' => $this->selectedDistrictIds(),
        ];
    }

    public function render()
    {
        $racks = $this->scopeAssetsForViewing(
            IctRack::query()->with(AssetListColumns::relations(AssetListColumns::RACKS))
        )
            ->tap(fn ($query) => app(AssetListQuery::class)->racks($query, $this->listFilters()))
            ->orderBy('ict_racks.name')
            ->paginate($this->perPage);

        $seesAll = $this->actorSeesAllRegions();

        return view('livewire.assets.racks.racks-list', [
            'racks' => $racks,
            ...$this->columnViewData(),
            ...$this->exportViewData('assets.export_inventory'),
            ...$this->regionViewData(),
            'statusOptions' => IctRack::STATUSES,
            'districtOptions' => $this->viewableDistricts()->mapWithKeys(fn ($district) => [
                (string) $district->id => $district->district_name.($seesAll && $district->region ? ' ('.$district->region->region_name.')' : ''),
            ])->all(),
            'selectedDistricts' => array_map('strval', $this->selectedDistrictIds()),
            'canManage' => RackService::mayManage($this->actor()),
            'deletingName' => $this->deletingRackId
                ? (string) IctRack::query()->whereKey($this->deletingRackId)->value('name')
                : '',
        ]);
    }
}
