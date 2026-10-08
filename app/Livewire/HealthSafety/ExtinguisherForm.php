<?php

namespace App\Livewire\HealthSafety;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\HealthSafety\Concerns\PicksEquipmentLocation;
use App\Livewire\HealthSafety\Concerns\ScopesHealthSafetyByActor;
use App\Models\HsFireExtinguisher;
use App\Models\Permission;
use App\Services\HealthSafety\FireExtinguisherService;
use App\Services\HealthSafety\HealthSafetySettings;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Add or edit an extinguisher: what it is, where it is (a site or a vehicle) and the dates that drive its state. The
 * next service and next hydrostatic test dates are pre-filled from the last ones, using the configured intervals, and
 * stay editable: the real hydrostatic interval depends on the extinguisher type.
 */
class ExtinguisherForm extends Component
{
    use EnforcesModuleAccess;
    use PicksEquipmentLocation;
    use ScopesHealthSafetyByActor;

    #[Locked]
    public ?int $extinguisherId = null;

    public string $assetCode = '';

    public string $serialNumber = '';

    public string $extinguisherType = '';

    public string $capacity = '';

    public string $manufacturer = '';

    public string $manufacturedOn = '';

    public string $expiryDate = '';

    public string $lastServicedOn = '';

    public string $nextServiceDue = '';

    public string $lastHydroTestOn = '';

    public string $nextHydroTestDue = '';

    public string $notes = '';

    public function mount(?HsFireExtinguisher $extinguisher = null): void
    {
        $this->enforceLivewireModule(Permission::MODULE_HEALTH_SAFETY);
        $this->guardHealthSafetyPermission('health_safety.manage_equipment');

        $this->regionId = $this->actorRegionId();

        if ($extinguisher?->exists) {
            abort_unless($this->equipmentScope()->canManage($this->actor(), $extinguisher), 403, 'You may not change this extinguisher.');
            abort_if($extinguisher->status === HsFireExtinguisher::STATUS_DECOMMISSIONED, 403, 'A decommissioned extinguisher cannot be edited.');

            $extinguisher->loadMissing(['vehicle', 'responsible']);

            $this->extinguisherId = $extinguisher->id;
            $this->assetCode = $extinguisher->asset_code;
            $this->serialNumber = (string) $extinguisher->serial_number;
            $this->extinguisherType = $extinguisher->extinguisher_type;
            $this->capacity = (string) $extinguisher->capacity;
            $this->manufacturer = (string) $extinguisher->manufacturer;
            $this->manufacturedOn = $extinguisher->manufactured_on?->toDateString() ?? '';
            $this->expiryDate = $extinguisher->expiry_date?->toDateString() ?? '';
            $this->lastServicedOn = $extinguisher->last_serviced_on?->toDateString() ?? '';
            $this->nextServiceDue = $extinguisher->next_service_due?->toDateString() ?? '';
            $this->lastHydroTestOn = $extinguisher->last_hydro_test_on?->toDateString() ?? '';
            $this->nextHydroTestDue = $extinguisher->next_hydro_test_due?->toDateString() ?? '';
            $this->notes = (string) $extinguisher->notes;
            $this->fillLocation($extinguisher);
        }
    }

    /** Typing the last service pre-fills the next one a configured number of months later (editable). */
    public function updatedLastServicedOn(string $value): void
    {
        if ($this->nextServiceDue === '' && $date = $this->parse($value)) {
            $this->nextServiceDue = $date->copy()->addMonths((int) HealthSafetySettings::value('hs_extinguisher_service_months'))->toDateString();
        }
    }

    /** Typing the last hydrostatic test pre-fills the next one (a pre-fill only; the real interval depends on the type). */
    public function updatedLastHydroTestOn(string $value): void
    {
        if ($this->nextHydroTestDue === '' && $date = $this->parse($value)) {
            $this->nextHydroTestDue = $date->copy()->addYears((int) HealthSafetySettings::value('hs_extinguisher_hydro_years'))->toDateString();
        }
    }

    public function save(FireExtinguisherService $service)
    {
        $this->guardHealthSafetyPermission('health_safety.manage_equipment');

        $this->validate([
            'extinguisherType' => ['required', 'in:'.implode(',', array_keys(HsFireExtinguisher::TYPES))],
            'assetCode' => ['nullable', 'string', 'max:40'],
            'serialNumber' => ['nullable', 'string', 'max:100'],
            'capacity' => ['nullable', 'string', 'max:50'],
            'manufacturer' => ['nullable', 'string', 'max:100'],
            'manufacturedOn' => ['nullable', 'date_format:Y-m-d'],
            'expiryDate' => ['nullable', 'date_format:Y-m-d'],
            'lastServicedOn' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'nextServiceDue' => ['nullable', 'date_format:Y-m-d'],
            'lastHydroTestOn' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'nextHydroTestDue' => ['nullable', 'date_format:Y-m-d'],
            'locationDetail' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], ['extinguisherType.required' => 'Choose the type.'], ['extinguisherType' => 'type']);

        $data = [
            ...$this->locationData(),
            'asset_code' => $this->assetCode,
            'serial_number' => $this->serialNumber,
            'extinguisher_type' => $this->extinguisherType,
            'capacity' => $this->capacity,
            'manufacturer' => $this->manufacturer,
            'manufactured_on' => $this->manufacturedOn ?: null,
            'expiry_date' => $this->expiryDate ?: null,
            'last_serviced_on' => $this->lastServicedOn ?: null,
            'next_service_due' => $this->nextServiceDue ?: null,
            'last_hydro_test_on' => $this->lastHydroTestOn ?: null,
            'next_hydro_test_due' => $this->nextHydroTestDue ?: null,
            'notes' => $this->notes,
        ];

        $extinguisher = $this->extinguisherId
            ? $service->update(HsFireExtinguisher::query()->findOrFail($this->extinguisherId), $this->actor(), $data)
            : $service->create($this->actor(), $data);

        $this->dispatch('toast', type: 'success', message: 'Extinguisher saved.');

        return $this->redirectRoute('health_safety.extinguishers.show', $extinguisher, navigate: false);
    }

    protected function parse(string $value): ?Carbon
    {
        try {
            return $value !== '' ? Carbon::createFromFormat('Y-m-d', $value)->startOfDay() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function render()
    {
        return view('livewire.health_safety.extinguisher-form', [
            ...$this->locationOptions(),
            'types' => HsFireExtinguisher::TYPES,
            'editing' => $this->extinguisherId !== null,
            'serviceMonths' => (int) HealthSafetySettings::value('hs_extinguisher_service_months'),
            'hydroYears' => (int) HealthSafetySettings::value('hs_extinguisher_hydro_years'),
        ]);
    }
}
