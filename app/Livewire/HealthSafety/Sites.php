<?php

namespace App\Livewire\HealthSafety;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\HealthSafety\Concerns\ScopesHealthSafetyByActor;
use App\Models\District;
use App\Models\HsFireExtinguisher;
use App\Models\HsFirstAidKit;
use App\Models\HsPpeStockMovement;
use App\Models\HsSite;
use App\Models\Permission;
use App\Models\Region;
use App\Services\HealthSafety\EquipmentLabelService;
use App\Services\HealthSafety\LabelBatch;
use App\Services\HealthSafety\QrLinks;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The register of places incidents happen (and, from Phase 2, equipment lives): head and regional offices, district
 * offices, pay points, depots. Reporters pick a pay point from it instead of typing a name three different ways.
 * Sites are deactivated, never deleted. Whoever does not see every region manages their own region's sites only.
 */
class Sites extends Component
{
    use EnforcesModuleAccess;
    use ScopesHealthSafetyByActor;
    use WithPagination;

    public string $search = '';

    /** @var list<int|string> sites ticked for a poster */
    public array $selectedSites = [];

    public bool $showInactive = false;

    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    public string $name = '';

    public string $kind = HsSite::KIND_PAY_POINT;

    public ?int $regionId = null;

    public ?int $districtId = null;

    public string $address = '';

    /** A site that holds PPE stock: stock can be received into it and issued from it. */
    public bool $isPpeStore = false;

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_HEALTH_SAFETY);
        $this->guardHealthSafetyPermission('health_safety.manage_master_data');

        $this->regionId = $this->actorRegionId();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedShowInactive(): void
    {
        $this->resetPage();
    }

    public function updatedRegionId(): void
    {
        $this->districtId = null;
    }

    public function create(): void
    {
        $this->guardHealthSafetyPermission('health_safety.manage_master_data');

        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $siteId): void
    {
        $this->guardHealthSafetyPermission('health_safety.manage_master_data');
        $site = $this->siteForActor($siteId);

        $this->editingId = $site->id;
        $this->name = $site->name;
        $this->kind = $site->kind;
        $this->regionId = $site->region_id;
        $this->districtId = $site->district_id;
        $this->address = (string) $site->address;
        $this->isPpeStore = (bool) $site->is_ppe_store;
        $this->showForm = true;
        $this->resetErrorBag();
    }

    /** Posters (one A4 page per site, a QR that opens the report form with the site chosen). Hand-over as for the labels. */
    public function printPosters(LabelBatch $batches): void
    {
        $this->guardHealthSafetyPermission('health_safety.manage_master_data');
        $this->resetErrorBag('posters');

        $ids = array_map('intval', $this->selectedSites);

        if ($ids === []) {
            $this->addError('posters', 'Tick at least one site to print a poster for.');

            return;
        }

        if (count($ids) > EquipmentLabelService::max()) {
            $this->addError('posters', 'A PDF holds at most '.EquipmentLabelService::max().' posters. Tick fewer sites.');

            return;
        }

        $token = $batches->stash($this->actor(), $ids, EquipmentLabelService::LAYOUT_STANDARD);
        $this->selectedSites = [];
        $this->redirect(route('health_safety.posters', ['batch' => $token]));
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function save(): void
    {
        $this->guardHealthSafetyPermission('health_safety.manage_master_data');

        $site = $this->editingId ? $this->siteForActor($this->editingId) : null;

        // Someone who does not see every region records sites against their own, whatever the request says.
        if (! $this->actorSeesAllRegions()) {
            $this->regionId = $this->actorRegionId();
        }

        if (! $this->regionId) {
            throw ValidationException::withMessages(['regionId' => 'Choose the region.']);
        }

        $this->validate([
            'name' => ['required', 'string', 'max:150'],
            'kind' => ['required', Rule::in(array_keys(HsSite::KINDS))],
            'regionId' => ['required', 'integer', Rule::exists('regions', 'id')],
            'districtId' => ['nullable', 'integer', Rule::exists('districts', 'id')->where('region_id', $this->regionId)],
            'address' => ['nullable', 'string', 'max:255'],
        ], [
            'districtId.exists' => 'That district is not in the chosen region.',
        ]);

        $name = trim($this->name);

        $taken = HsSite::query()
            ->where('region_id', $this->regionId)
            ->where('kind', $this->kind)
            ->where('name', $name)
            ->when($site, fn (Builder $query) => $query->whereKeyNot($site->id))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name' => 'This region already has a site of this kind with that name.']);
        }

        $attributes = [
            'name' => $name,
            'kind' => $this->kind,
            'region_id' => $this->regionId,
            'district_id' => $this->districtId,
            'address' => trim($this->address) ?: null,
            'is_ppe_store' => $this->isPpeStore,
        ];

        // A store that still holds PPE cannot stop being one: the stock would be left where nobody can reach it.
        if ($site && $site->is_ppe_store && ! $this->isPpeStore && $this->holdsStock($site)) {
            throw ValidationException::withMessages(['isPpeStore' => 'This store still holds PPE. Transfer or write the stock off first.']);
        }

        DB::transaction(function () use (&$site, $attributes) {
            if ($site) {
                $site->update($attributes);

                // Equipment copies its region and district from its site, so it follows when the site moves.
                foreach ([HsFireExtinguisher::class, HsFirstAidKit::class] as $equipment) {
                    $equipment::query()->where('site_id', $site->id)->update([
                        'region_id' => $site->region_id,
                        'district_id' => $site->district_id,
                    ]);
                }
            } else {
                $site = HsSite::query()->create([...$attributes, 'is_active' => true, 'created_by' => $this->actor()->id]);
            }
        });

        Audit::log('health_safety.site_saved', 'health_safety', 'hs_sites', $site->id, ['name' => $site->name, 'kind' => $site->kind]);

        $this->resetForm();
        $this->dispatch('toast', type: 'success', message: 'Site saved.');
    }

    public function toggleActive(int $siteId): void
    {
        $this->guardHealthSafetyPermission('health_safety.manage_master_data');
        $site = $this->siteForActor($siteId);

        if ($site->is_active && $site->is_ppe_store && $this->holdsStock($site)) {
            throw ValidationException::withMessages(['isPpeStore' => 'This store still holds PPE. Transfer or write the stock off before deactivating it.']);
        }

        $site->update(['is_active' => ! $site->is_active]);

        Audit::log('health_safety.site_saved', 'health_safety', 'hs_sites', $site->id, ['name' => $site->name, 'is_active' => $site->is_active]);
        $this->dispatch('toast', type: 'success', message: $site->is_active ? 'Site reactivated.' : 'Site deactivated.');
    }

    /** A site the actor may manage; one in another region is a 403, never "not found". */
    protected function siteForActor(int $siteId): HsSite
    {
        $site = HsSite::query()->findOrFail($siteId);

        abort_unless($this->actorSeesAllRegions() || (int) $site->region_id === (int) $this->actorRegionId(), 403, 'This site belongs to another region.');

        return $site;
    }

    /** Whether any PPE type and size has a non-zero balance in the site. */
    protected function holdsStock(HsSite $site): bool
    {
        return HsPpeStockMovement::query()
            ->where('site_id', $site->id)
            ->select('ppe_type_id', 'size')
            ->groupBy('ppe_type_id', 'size')
            ->havingRaw('sum(quantity) <> 0')
            ->get()
            ->isNotEmpty();
    }

    protected function resetForm(): void
    {
        $this->reset(['showForm', 'editingId', 'name', 'address', 'districtId', 'isPpeStore']);
        $this->kind = HsSite::KIND_PAY_POINT;
        $this->regionId = $this->actorRegionId();
        $this->resetErrorBag();
    }

    public function render()
    {
        $seesAll = $this->actorSeesAllRegions();

        $sites = HsSite::query()
            ->with(['region', 'district'])
            ->when(! $seesAll, fn (Builder $query) => $query->where('region_id', $this->actorRegionId() ?? 0))
            ->when(! $this->showInactive, fn (Builder $query) => $query->where('is_active', true))
            ->when(trim($this->search) !== '', fn (Builder $query) => $query->where('name', 'like', '%'.trim($this->search).'%'))
            ->orderBy('name')
            ->paginate(15);

        return view('livewire.health_safety.sites', [
            'qrBase' => app(QrLinks::class)->baseUrl(),
            'sites' => $sites,
            'kinds' => HsSite::KINDS,
            'seesAll' => $seesAll,
            'regions' => $seesAll ? Region::query()->orderBy('region_name')->get(['id', 'region_name']) : collect(),
            'districts' => District::query()->where('region_id', $this->regionId ?? 0)->orderBy('district_name')->get(['id', 'district_name']),
        ]);
    }
}
