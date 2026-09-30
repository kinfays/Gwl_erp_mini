<?php

namespace App\Livewire\Assets;

use App\Livewire\Assets\Concerns\ScopesAssetsByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\District;
use App\Models\Employee;
use App\Models\IctAsset;
use App\Models\IctAssetModel;
use App\Models\MdmDevice;
use App\Models\MdmDeviceCommand;
use App\Services\Assets\AssetRecordService;
use App\Services\Assets\Mdm\CommandService;
use App\Services\Assets\Mdm\MdmAccessGuard;
use App\Services\Assets\Mdm\MdmSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class PhonesList extends Component
{
    use EnforcesModuleAccess;
    use ScopesAssetsByActor;
    use WithPagination;

    public const CATEGORY = IctAsset::DEVICE_CATEGORY_PHONE;

    public string $search = '';

    public string $status = '';

    public string $assetType = '';

    public int $perPage = 15;

    public bool $showForm = false;

    public ?int $editingAssetId = null;

    public ?string $previousAssignedLabel = null;

    /**
     * Set when a status change on an enrolled phone should offer Lost Mode ('start') or Stop Lost Mode ('stop').
     * Locked and re-resolved through MdmAccessGuard when confirmed, so the client cannot aim it at another phone.
     */
    #[Locked]
    public ?int $mdmPromptDeviceId = null;

    #[Locked]
    public ?string $mdmPromptAction = null;

    /** Editable on the Lost Mode offer; start out as the configured defaults. */
    public string $mdmPromptMessage = '';

    public string $mdmPromptPhone = '';

    /**
     * form.region_id is display-only. It has no validation rule, so it never
     * reaches save(), which always stamps the actor's own region instead.
     */
    public array $form = [
        'asset_name' => '',
        'serial_number' => '',
        'asset_type' => '',
        'imei' => '',
        'ict_asset_model_id' => null,
        'status' => 'Active',
        'assigned_to_employee_id' => null,
        'region_id' => null,
        'district_id' => null,
        'user_phone_number' => '',
        'device_phone_number' => '',
    ];

    public function mount(): void
    {
        $this->enforceLivewireModule('assets');

        $this->form['region_id'] = $this->actorRegionId();
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'status', 'assetType', 'perPage'], true)) {
            $this->resetPage();
        }
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
            ->with('previousAssignedTo')
            ->findOrFail($assetId);

        $this->editingAssetId = $asset->id;
        $this->showForm = true;
        $this->previousAssignedLabel = $asset->previousAssignedTo?->full_name;
        $this->form = [
            'asset_name' => (string) $asset->asset_name,
            'serial_number' => (string) $asset->serial_number,
            'asset_type' => (string) $asset->asset_type,
            'imei' => (string) $asset->imei,
            'ict_asset_model_id' => $asset->ict_asset_model_id,
            'status' => (string) $asset->status,
            'assigned_to_employee_id' => $asset->assigned_to_employee_id,
            'region_id' => $this->actorRegionId(),
            'district_id' => $asset->district_id,
            'user_phone_number' => (string) $asset->user_phone_number,
            'device_phone_number' => (string) $asset->device_phone_number,
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

        $regionId = $this->requireActorRegionId();

        $validated = $this->validate($this->rules())['form'];
        $validated['region_id'] = $regionId;

        if (empty($validated['district_id']) && ! empty($validated['assigned_to_employee_id'])) {
            $validated['district_id'] = $this->assigneeDistrictWithinRegion((int) $validated['assigned_to_employee_id'], $regionId);
        }

        $existing = $this->editingAssetId
            ? $this->scopeAssetsForActor(IctAsset::query())->where('device_category', self::CATEGORY)->findOrFail($this->editingAssetId)
            : null;

        $previousStatus = $existing?->status;

        $asset = app(AssetRecordService::class)->save($validated, self::CATEGORY, $existing);

        $this->dispatch('toast', type: 'success', message: $existing ? 'Phone device updated successfully.' : 'Phone device created successfully.');
        $this->closeForm();
        $this->offerMdmLostMode($previousStatus, $asset);
    }

    /**
     * Marking an enrolled phone Lost offers to start Lost Mode; recovering it offers to stop it. Only an offer:
     * nothing is sent until the user confirms in the modal, and it never wipes.
     */
    protected function offerMdmLostMode(?string $previousStatus, IctAsset $asset): void
    {
        $this->mdmPromptDeviceId = null;
        $this->mdmPromptAction = null;

        if (! config('gwl.mdm_enabled') || ! $this->canUseMdmCommands()) {
            return;
        }

        $device = MdmDevice::query()->notDeleted()->where('ict_asset_id', $asset->id)->first();

        if (! $device || $device->needs_review) {
            return;
        }

        $nowLost = $asset->status === IctAsset::STATUS_LOST;
        $wasLost = $previousStatus === IctAsset::STATUS_LOST;

        if ($nowLost && ! $wasLost && ! $device->is_lost) {
            $defaults = app(MdmSettings::class)->lostModeDefaults();

            $this->mdmPromptDeviceId = $device->id;
            $this->mdmPromptAction = 'start';
            $this->mdmPromptMessage = (string) $defaults['message'];
            $this->mdmPromptPhone = (string) $defaults['phone'];
        } elseif ($wasLost && ! $nowLost && $device->is_lost) {
            $this->mdmPromptDeviceId = $device->id;
            $this->mdmPromptAction = 'stop';
        }
    }

    public function confirmMdmPrompt(): void
    {
        $action = $this->mdmPromptAction;

        abort_unless(config('gwl.mdm_enabled') && $this->mdmPromptDeviceId && in_array($action, ['start', 'stop'], true), 404);

        $device = app(MdmAccessGuard::class)->deviceOrFail($this->actor(), $this->mdmPromptDeviceId);

        try {
            app(CommandService::class)->request(
                $this->actor(),
                $device,
                $action === 'start' ? MdmDeviceCommand::TYPE_START_LOST_MODE : MdmDeviceCommand::TYPE_STOP_LOST_MODE,
                $action === 'start' ? ['message' => $this->mdmPromptMessage, 'phone' => $this->mdmPromptPhone] : [],
            );
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        $this->dismissMdmPrompt();
        $this->dispatch('toast', type: 'success', message: $action === 'start' ? 'Lost Mode queued.' : 'Stop Lost Mode queued.');
    }

    public function dismissMdmPrompt(): void
    {
        $this->mdmPromptDeviceId = null;
        $this->mdmPromptAction = null;
        $this->resetErrorBag('command');
    }

    protected function canUseMdmCommands(): bool
    {
        return app(MdmAccessGuard::class)->has($this->actor(), 'assets.mdm_command');
    }

    /**
     * Location still defaults to the assignee's district, but only when that
     * district sits inside the actor's region — region is locked to the actor,
     * so an out-of-region district would contradict it.
     */
    protected function assigneeDistrictWithinRegion(int $employeeId, int $regionId): ?int
    {
        $districtId = Employee::query()->whereKey($employeeId)->value('district_id');

        return $districtId && District::query()->whereKey($districtId)->where('region_id', $regionId)->exists()
            ? (int) $districtId
            : null;
    }

    protected function rules(): array
    {
        return [
            'form.asset_name' => ['required', 'string', 'max:255'],
            'form.serial_number' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('ict_assets', 'serial_number')->ignore($this->editingAssetId),
            ],
            'form.asset_type' => ['required', Rule::in(array_keys(IctAsset::ASSET_TYPES[self::CATEGORY]))],
            'form.imei' => ['nullable', 'string', 'max:50'],
            'form.ict_asset_model_id' => ['nullable', 'integer', 'exists:ict_asset_models,id'],
            'form.status' => ['required', 'string', 'max:120'],
            'form.assigned_to_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'form.district_id' => ['nullable', 'integer', $this->actorRegionDistrictRule()],
            'form.user_phone_number' => ['nullable', 'string', 'max:30'],
            'form.device_phone_number' => ['nullable', 'string', 'max:30'],
        ];
    }

    protected function messages(): array
    {
        return [
            'form.district_id.exists' => 'The selected location is not in your region.',
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
        $this->previousAssignedLabel = null;
        $this->form = [
            'asset_name' => '',
            'serial_number' => '',
            'asset_type' => '',
            'imei' => '',
            'ict_asset_model_id' => null,
            'status' => 'Active',
            'assigned_to_employee_id' => null,
            'region_id' => $this->actorRegionId(),
            'district_id' => null,
            'user_phone_number' => '',
            'device_phone_number' => '',
        ];
    }

    public function render()
    {
        $assets = $this->scopeAssetsForViewing(
            IctAsset::query()->with(['assetModel', 'assignedTo', 'district', 'region', 'mdmDevice'])
        )
            ->where('device_category', self::CATEGORY)
            ->when($this->search, function ($query) {
                $term = '%'.$this->search.'%';
                $query->where(function ($inner) use ($term) {
                    $inner
                        ->where('asset_name', 'like', $term)
                        ->orWhere('serial_number', 'like', $term)
                        ->orWhere('imei', 'like', $term)
                        ->orWhere('device_phone_number', 'like', $term)
                        ->orWhereHas('assignedTo', fn ($employee) => $employee->where('full_name', 'like', $term));
                });
            })
            ->when($this->status, fn ($query) => $query->where('status', $this->status))
            ->when($this->assetType, fn ($query) => $query->where('asset_type', $this->assetType))
            ->latest()
            ->paginate($this->perPage);

        $models = IctAssetModel::query()
            ->with('manufacturer')
            ->where('is_active', true)
            ->when($this->form['asset_type'], fn ($query) => $query->where('category', $this->form['asset_type']))
            ->orderBy('name')
            ->get();

        $employees = Employee::query()
            ->when($this->actorIsRegionScopedIct(), fn ($query) => $query->where('region_id', $this->actorRegionId()))
            ->orderBy('full_name')
            ->limit(500)
            ->get();

        $mdmEnabled = (bool) config('gwl.mdm_enabled');

        return view('livewire.assets.phones-list', [
            'mdmLinks' => $mdmEnabled && app(MdmAccessGuard::class)->has($this->actor(), 'assets.mdm_view'),
            'mdmPromptDevice' => $mdmEnabled && $this->mdmPromptDeviceId
                ? app(MdmAccessGuard::class)->devices($this->actor())->with('asset.assignedTo')->find($this->mdmPromptDeviceId)
                : null,
            'assets' => $assets,
            'assetTypes' => IctAsset::ASSET_TYPES[self::CATEGORY],
            'statusOptions' => [IctAsset::STATUS_ACTIVE, IctAsset::STATUS_IN_REPAIR, IctAsset::STATUS_RETIRED, IctAsset::STATUS_LOST],
            'formDistricts' => $this->actorRegionDistricts(),
            'actorRegion' => $this->actorRegion(),
            ...$this->regionViewData(),
            'models' => $models,
            'employees' => $employees,
        ]);
    }
}
