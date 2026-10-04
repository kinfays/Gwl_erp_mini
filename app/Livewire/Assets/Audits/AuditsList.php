<?php

namespace App\Livewire\Assets\Audits;

use App\Livewire\Assets\Concerns\ScopesAssetsByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\District;
use App\Models\IctAsset;
use App\Models\IctAssetAudit;
use App\Models\Region;
use App\Services\Assets\AssetAuditService;
use App\Services\Assets\AuditVisibility;
use Livewire\Component;
use Livewire\WithPagination;

class AuditsList extends Component
{
    use EnforcesModuleAccess;
    use ScopesAssetsByActor;
    use WithPagination;

    public bool $showForm = false;

    public string $title = '';

    public string $deviceCategory = '';

    public int|string $regionId = '';

    public int|string $districtId = '';

    public function mount(): void
    {
        $this->enforceLivewireModule('assets');
        $this->authorizeManage();
    }

    public function openCreate(): void
    {
        $this->authorizeManage();
        $this->reset('title', 'deviceCategory', 'regionId', 'districtId');
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
    }

    public function updatedRegionId(): void
    {
        // A district only makes sense inside the chosen region.
        if ($this->districtId !== '' && $this->regionId !== ''
            && ! District::query()->whereKey($this->districtId)->where('region_id', $this->regionId)->exists()) {
            $this->districtId = '';
        }
    }

    public function create(AssetAuditService $service): void
    {
        $this->authorizeManage();

        $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'deviceCategory' => ['nullable', 'in:'.implode(',', IctAsset::DEVICE_CATEGORIES)],
            'regionId' => ['nullable', 'integer', 'exists:regions,id'],
            'districtId' => ['nullable', 'integer', 'exists:districts,id'],
        ]);

        $audit = $service->start($this->scope(), $this->title, (int) $this->actor()->id);

        $this->redirectRoute('assets.audits.show', $audit, navigate: false);
    }

    /** The scope as it will be applied: a regional ICT user is always held to their own region. */
    protected function scope(): array
    {
        $region = $this->actorSeesAllRegions() ? ($this->regionId ?: null) : $this->actorRegionId();

        return [
            'device_category' => $this->deviceCategory ?: null,
            'region_id' => $region,
            'district_id' => $this->districtId ?: null,
        ];
    }

    protected function authorizeManage(): void
    {
        $user = $this->actor();

        abort_unless($user->hasRoles('super_admin') || $user->hasPermission('assets.manage_audits'), 403);
    }

    public function render(AssetAuditService $service)
    {
        $audits = AuditVisibility::scope(IctAssetAudit::query(), $this->actor())
            ->with(['startedBy', 'region', 'district'])
            ->latest('started_at')
            ->latest('id')
            ->paginate(15);

        $regions = Region::query()
            ->when(! $this->actorSeesAllRegions(), fn ($q) => $q->whereKey($this->actorRegionId() ?? 0))
            ->orderBy('region_name')
            ->get();

        $effectiveRegion = $this->actorSeesAllRegions() ? $this->regionId : $this->actorRegionId();

        return view('livewire.assets.audits.audits-list', [
            ...$this->regionViewData(),
            'audits' => $audits,
            'regions' => $regions,
            'districts' => District::query()
                ->when($effectiveRegion, fn ($q) => $q->where('region_id', $effectiveRegion))
                ->orderBy('district_name')
                ->get(),
            'categories' => ['asset' => 'Assets', 'phone' => 'Phones', 'network' => 'Network'],
            'lineCount' => $this->showForm ? $service->scopeQuery($this->scope())->count() : 0,
        ]);
    }
}
