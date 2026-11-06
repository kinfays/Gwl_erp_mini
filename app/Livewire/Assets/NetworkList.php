<?php

namespace App\Livewire\Assets;

use App\Livewire\Assets\Concerns\CreatesModelInline;
use App\Livewire\Assets\Concerns\ExportsAssetLists;
use App\Livewire\Assets\Concerns\FiltersByAnalytics;
use App\Livewire\Assets\Concerns\FiltersByAssetTypes;
use App\Livewire\Assets\Concerns\ResetsModelOnTypeChange;
use App\Livewire\Assets\Concerns\ScopesAssetsByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\Concerns\HasSelectableColumns;
use App\Models\IctAsset;
use App\Rules\UniqueAssetName;
use App\Models\IctAssetModel;
use App\Services\Assets\AssetListColumns;
use App\Services\Assets\AssetListQuery;
use App\Services\Assets\AssetRecordService;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class NetworkList extends Component
{
    use CreatesModelInline;
    use EnforcesModuleAccess;
    use ExportsAssetLists;
    use FiltersByAnalytics;
    use FiltersByAssetTypes;
    use HasSelectableColumns;
    use ResetsModelOnTypeChange;
    use ScopesAssetsByActor;
    use WithPagination;

    public const CATEGORY = IctAsset::DEVICE_CATEGORY_NETWORK;

    /** Bound to ?q= so summary pages can link straight to one device. */
    #[Url(as: 'q', except: '')]
    public string $search = '';

    public string $status = '';

    public int $perPage = 15;

    public bool $showForm = false;

    public ?int $editingAssetId = null;

    /**
     * Row whose LoginPW/SsidPW are currently decrypted for display. Gated by
     * assets.view_network_secrets in toggleReveal() and re-checked in
     * render() before either secret is ever handed to the view.
     */
    public ?int $revealedAssetId = null;

    /**
     * form.region_id is display-only. It has no validation rule, so it never
     * reaches save(), which always stamps the actor's own region instead.
     */
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
        'status_reason' => '',
    ];

    public function mount(): void
    {
        $this->enforceLivewireModule('assets');

        $this->form['region_id'] = $this->actorRegionId();
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'status', 'assetType', 'perPage', ...$this->analyticsFilterProperties()], true)) {
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
            'region_id' => $this->actorRegionId(),
            'serial_number' => (string) $asset->serial_number,
            'actual_location' => (string) $asset->actual_location,
            'status' => (string) $asset->status,
            'status_reason' => (string) $asset->status_reason,
        ];
    }

    /** Read-only transfer history for the asset being edited; limited to assets the actor can view. */
    protected function historyFor(int $assetId)
    {
        $asset = $this->scopeAssetsForViewing(IctAsset::query())
            ->where('device_category', self::CATEGORY)
            ->find($assetId);

        return $asset
            ? $asset->transfers()->with(['fromEmployee', 'toEmployee', 'fromDistrict', 'toDistrict', 'relatedAsset'])->limit(50)->get()
            : collect();
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->editingAssetId = null;
        $this->closeNewModel();
        $this->resetForm();
    }

    public function save(): void
    {
        $permission = $this->editingAssetId ? 'assets.edit' : 'assets.create';
        $this->authorizeAction($permission);

        $regionId = $this->requireActorRegionId();

        $validated = $this->validate($this->rules())['form'];
        $validated['region_id'] = $regionId;

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
            'form.asset_name' => ['required', 'string', 'max:255', new UniqueAssetName($this->editingAssetId)],
            'form.asset_type' => ['required', Rule::in(array_keys(IctAsset::ASSET_TYPES[self::CATEGORY]))],
            'form.ict_asset_model_id' => ['nullable', 'integer', 'exists:ict_asset_models,id'],
            'form.device_username' => ['nullable', 'string', 'max:255'],
            'form.login_password' => ['nullable', 'string', 'max:255'],
            'form.ssid' => ['nullable', 'string', 'max:255'],
            'form.ssid_password' => ['nullable', 'string', 'max:255'],
            'form.device_ip' => ['nullable', 'ip'],
            'form.district_id' => ['nullable', 'integer', $this->actorRegionDistrictRule()],
            'form.serial_number' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('ict_assets', 'serial_number')->ignore($this->editingAssetId),
            ],
            'form.actual_location' => ['nullable', 'string', 'max:255'],
            'form.status' => ['required', 'string', 'max:120'],
            'form.status_reason' => [Rule::requiredIf(fn () => ($this->form['status'] ?? null) === IctAsset::STATUS_DAMAGED), 'nullable', 'string', 'max:1000'],
        ];
    }

    protected function messages(): array
    {
        return [
            'form.district_id.exists' => 'The selected district is not in your region.',
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
            'region_id' => $this->actorRegionId(),
            'serial_number' => '',
            'actual_location' => '',
            'status' => 'Active',
            'status_reason' => '',
        ];
    }

    protected function columnManifest(): array
    {
        return AssetListColumns::manifest(AssetListColumns::NETWORK);
    }

    /** The list's filters, named as AssetListQuery (and the export links) name them. */
    protected function listFilters(): array
    {
        return [
            'search' => $this->search,
            'status' => $this->status,
            'type' => $this->assetType,
            ...$this->analyticsFilterValues(),
        ];
    }

    public function render()
    {
        $user = $this->actor();
        $canViewSecrets = $user->hasRoles('super_admin') || $user->hasPermission('assets.view_network_secrets');

        $assets = $this->scopeAssetsForViewing(
            IctAsset::query()->with(['assetModel', 'district', 'region'])
        )
            ->tap(fn ($query) => app(AssetListQuery::class)->assets($query, self::CATEGORY, $this->listFilters()))
            ->latest()
            ->paginate($this->perPage);

        $models = IctAssetModel::query()
            ->with('manufacturer')
            ->where('is_active', true)
            ->when($this->form['asset_type'], fn ($query) => $query->where('category', $this->form['asset_type']))
            ->orderBy('name')
            ->get();

        return view('livewire.assets.network-list', [
            'analyticsChips' => $this->analyticsFilterChips(),
            ...$this->columnViewData(),
            ...$this->exportViewData('assets.export_inventory'),
            'history' => $this->editingAssetId ? $this->historyFor($this->editingAssetId) : collect(),
            'assets' => $assets,
            'assetTypes' => IctAsset::ASSET_TYPES[self::CATEGORY],
            'selectedTypes' => $this->selectedAssetTypes(),
            'statusOptions' => IctAsset::STATUSES,
            'formDistricts' => $this->actorRegionDistricts(),
            'actorRegion' => $this->actorRegion(),
            ...$this->regionViewData(),
            'models' => $models,
            ...$this->addModelViewData(),
            'canViewSecrets' => $canViewSecrets,
        ]);
    }
}
