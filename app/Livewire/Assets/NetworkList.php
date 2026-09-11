<?php

namespace App\Livewire\Assets;

use App\Livewire\Assets\Concerns\ScopesAssetsByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\District;
use App\Models\IctAsset;
use App\Models\IctAssetModel;
use App\Models\Region;
use App\Services\Assets\AssetRecordService;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class NetworkList extends Component
{
    use EnforcesModuleAccess;
    use ScopesAssetsByActor;
    use WithPagination;

    public const CATEGORY = IctAsset::DEVICE_CATEGORY_NETWORK;

    public string $search = '';

    public string $status = '';

    public string $assetType = '';

    public int $perPage = 15;

    public bool $showForm = false;

    public ?int $editingAssetId = null;

    public bool $regionLocked = false;

    /**
     * Row whose LoginPW/SsidPW are currently decrypted for display. Gated by
     * assets.view_network_secrets in toggleReveal() and re-checked in
     * render() before either secret is ever handed to the view.
     */
    public ?int $revealedAssetId = null;

    public array $form = [
        'asset_name' => '',
        'asset_type' => '',
        'ict_asset_model_id' => null,
        'device_username' => '',
        'login_password' => '',
        'ssid' => '',
        'ssid_password' => '',
        'device_ip' => '',
        'district_id' => null,
        'region_id' => null,
        'serial_number' => '',
        'actual_location' => '',
        'status' => 'Active',
    ];

    public function mount(): void
    {
        $this->enforceLivewireModule('assets');

        $this->regionLocked = $this->actorIsRegionScopedIct();

        if ($this->regionLocked) {
            $this->form['region_id'] = $this->actorRegionId();
        }
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'status', 'assetType', 'perPage'], true)) {
            $this->resetPage();
        }

        if ($name !== 'revealedAssetId') {
            $this->revealedAssetId = null;
        }
    }

    public function toggleReveal(int $assetId): void
    {
        $this->authorizeAction('assets.view_network_secrets');

        $this->revealedAssetId = $this->revealedAssetId === $assetId ? null : $assetId;
    }

    public function openCreate(): void
    {
        $this->authorizeAction('assets.create');

        $this->editingAssetId = null;
        $this->showForm = true;
        $this->resetForm();
    }

    public function openEdit(int $assetId): void
    {
        $this->authorizeAction('assets.edit');

        $asset = $this->scopeAssetsForActor(IctAsset::query())
            ->where('device_category', self::CATEGORY)
            ->findOrFail($assetId);

        $this->editingAssetId = $asset->id;
        $this->showForm = true;
        $this->form = [
            'asset_name' => (string) $asset->asset_name,
            'asset_type' => (string) $asset->asset_type,
            'ict_asset_model_id' => $asset->ict_asset_model_id,
            'device_username' => (string) $asset->device_username,
            // Passwords are never pre-filled; leaving these blank on save
            // keeps the existing encrypted value untouched.
            'login_password' => '',
            'ssid' => (string) $asset->ssid,
            'ssid_password' => '',
            'device_ip' => (string) $asset->device_ip,
            'district_id' => $asset->district_id,
            'region_id' => $asset->region_id,
            'serial_number' => (string) $asset->serial_number,
            'actual_location' => (string) $asset->actual_location,
            'status' => (string) $asset->status,
        ];
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->editingAssetId = null;
        $this->resetForm();
    }

    public function save(): void
    {
        $permission = $this->editingAssetId ? 'assets.edit' : 'assets.create';
        $this->authorizeAction($permission);

        $validated = $this->validate($this->rules())['form'];

        if ($this->actorIsRegionScopedIct()) {
            $validated['region_id'] = $this->actorRegionId();
        }

        // Blank password fields mean "leave unchanged" on an edit.
        if ($this->editingAssetId) {
            if ($validated['login_password'] === '') {
                unset($validated['login_password']);
            }
            if ($validated['ssid_password'] === '') {
                unset($validated['ssid_password']);
            }
        }

        $existing = $this->editingAssetId
            ? $this->scopeAssetsForActor(IctAsset::query())->where('device_category', self::CATEGORY)->findOrFail($this->editingAssetId)
            : null;

        app(AssetRecordService::class)->save($validated, self::CATEGORY, $existing);

        $this->dispatch('toast', type: 'success', message: $existing ? 'Network device updated successfully.' : 'Network device created successfully.');
        $this->closeForm();
    }

    protected function rules(): array
    {
        return [
            'form.asset_name' => ['required', 'string', 'max:255'],
            'form.asset_type' => ['required', Rule::in(array_keys(IctAsset::ASSET_TYPES[self::CATEGORY]))],
            'form.ict_asset_model_id' => ['nullable', 'integer', 'exists:ict_asset_models,id'],
            'form.device_username' => ['nullable', 'string', 'max:255'],
            'form.login_password' => ['nullable', 'string', 'max:255'],
            'form.ssid' => ['nullable', 'string', 'max:255'],
            'form.ssid_password' => ['nullable', 'string', 'max:255'],
            'form.device_ip' => ['nullable', 'ip'],
            'form.district_id' => ['nullable', 'integer', 'exists:districts,id'],
            'form.region_id' => ['nullable', 'integer', 'exists:regions,id'],
            'form.serial_number' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('ict_assets', 'serial_number')->ignore($this->editingAssetId),
            ],
            'form.actual_location' => ['nullable', 'string', 'max:255'],
            'form.status' => ['required', 'string', 'max:120'],
        ];
    }

    protected function authorizeAction(string $permission): void
    {
        $user = $this->actor();

        if (! $user->hasRoles('super_admin') && ! $user->hasPermission($permission)) {
            abort(403, 'You do not have permission to perform this action.');
        }
    }

    protected function resetForm(): void
    {
        $this->form = [
            'asset_name' => '',
            'asset_type' => '',
            'ict_asset_model_id' => null,
            'device_username' => '',
            'login_password' => '',
            'ssid' => '',
            'ssid_password' => '',
            'device_ip' => '',
            'district_id' => null,
            'region_id' => $this->regionLocked ? $this->actorRegionId() : null,
            'serial_number' => '',
            'actual_location' => '',
            'status' => 'Active',
        ];
    }

    public function render()
    {
        $user = $this->actor();
        $canViewSecrets = $user->hasRoles('super_admin') || $user->hasPermission('assets.view_network_secrets');

        $assets = $this->scopeAssetsForActor(
            IctAsset::query()->with(['assetModel', 'district', 'region'])
        )
            ->where('device_category', self::CATEGORY)
            ->when($this->search, function ($query) {
                $term = '%'.$this->search.'%';
                $query->where(function ($inner) use ($term) {
                    $inner
                        ->where('asset_name', 'like', $term)
                        ->orWhere('serial_number', 'like', $term)
                        ->orWhere('device_ip', 'like', $term)
                        ->orWhere('ssid', 'like', $term)
                        ->orWhere('actual_location', 'like', $term);
                });
            })
            ->when($this->status, fn ($query) => $query->where('status', $this->status))
            ->when($this->assetType, fn ($query) => $query->where('asset_type', $this->assetType))
            ->latest()
            ->paginate($this->perPage);

        $districts = District::query()
            ->when($this->actorIsRegionScopedIct(), fn ($query) => $query->where('region_id', $this->actorRegionId()))
            ->orderBy('district_name')
            ->get();

        $regions = Region::query()
            ->when($this->actorIsRegionScopedIct(), fn ($query) => $query->whereKey($this->actorRegionId()))
            ->orderBy('region_name')
            ->get();

        $models = IctAssetModel::query()
            ->where('is_active', true)
            ->when($this->form['asset_type'], fn ($query) => $query->where('category', $this->form['asset_type']))
            ->orderBy('name')
            ->get();

        return view('livewire.assets.network-list', [
            'assets' => $assets,
            'assetTypes' => IctAsset::ASSET_TYPES[self::CATEGORY],
            'statusOptions' => [IctAsset::STATUS_ACTIVE, IctAsset::STATUS_IN_REPAIR, IctAsset::STATUS_RETIRED, IctAsset::STATUS_LOST],
            'districts' => $districts,
            'regions' => $regions,
            'models' => $models,
            'canViewSecrets' => $canViewSecrets,
        ]);
    }
}
