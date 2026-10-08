<?php

namespace App\Livewire\HealthSafety;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\HealthSafety\Concerns\PicksEquipmentLocation;
use App\Livewire\HealthSafety\Concerns\ScopesHealthSafetyByActor;
use App\Models\HsFirstAidItemTemplate;
use App\Models\HsFirstAidKit;
use App\Models\Permission;
use App\Services\HealthSafety\FirstAidKitService;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Add or edit a first aid kit. A new kit is filled with a COPY of the template for its type; editing its details later
 * never touches its contents (those are edited on the kit's own screen).
 */
class FirstAidKitForm extends Component
{
    use EnforcesModuleAccess;
    use PicksEquipmentLocation;
    use ScopesHealthSafetyByActor;

    #[Locked]
    public ?int $kitId = null;

    public string $assetCode = '';

    public string $kitType = '';

    public string $lastCheckedOn = '';

    public string $notes = '';

    public function mount(?HsFirstAidKit $kit = null): void
    {
        $this->enforceLivewireModule(Permission::MODULE_HEALTH_SAFETY);
        $this->guardHealthSafetyPermission('health_safety.manage_equipment');

        $this->regionId = $this->actorRegionId();

        if ($kit?->exists) {
            abort_unless($this->equipmentScope()->canManage($this->actor(), $kit), 403, 'You may not change this kit.');
            abort_if($kit->status === HsFirstAidKit::STATUS_DECOMMISSIONED, 403, 'A decommissioned kit cannot be edited.');

            $kit->loadMissing(['vehicle', 'responsible']);

            $this->kitId = $kit->id;
            $this->assetCode = $kit->asset_code;
            $this->kitType = $kit->kit_type;
            $this->lastCheckedOn = $kit->last_checked_on?->toDateString() ?? '';
            $this->notes = (string) $kit->notes;
            $this->fillLocation($kit);
        }
    }

    public function save(FirstAidKitService $service)
    {
        $this->guardHealthSafetyPermission('health_safety.manage_equipment');

        $this->validate([
            'kitType' => ['required', 'in:'.implode(',', array_keys(HsFirstAidKit::TYPES))],
            'assetCode' => ['nullable', 'string', 'max:40'],
            'lastCheckedOn' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'locationDetail' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], ['kitType.required' => 'Choose the kit type.'], ['kitType' => 'kit type']);

        $data = [
            ...$this->locationData(),
            'asset_code' => $this->assetCode,
            'kit_type' => $this->kitType,
            'notes' => $this->notes,
        ];

        // The date of a check that happened before this kit was entered; later checks are recorded on the kit.
        if (! $this->kitId) {
            $data['last_checked_on'] = $this->lastCheckedOn ?: null;
        }

        $kit = $this->kitId
            ? $service->update(HsFirstAidKit::query()->findOrFail($this->kitId), $this->actor(), $data)
            : $service->create($this->actor(), $data);

        $this->dispatch('toast', type: 'success', message: 'Kit saved.');

        return $this->redirectRoute('health_safety.kits.show', $kit, navigate: false);
    }

    public function render()
    {
        return view('livewire.health_safety.first-aid-kit-form', [
            ...$this->locationOptions(),
            'types' => HsFirstAidKit::TYPES,
            'editing' => $this->kitId !== null,
            'hasTemplates' => HsFirstAidItemTemplate::query()->exists(),
        ]);
    }
}
