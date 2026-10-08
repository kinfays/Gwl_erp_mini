<?php

namespace App\Livewire\HealthSafety;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\HealthSafety\Concerns\ScopesHealthSafetyByActor;
use App\Models\HsExtinguisherService;
use App\Models\HsFireExtinguisher;
use App\Models\Permission;
use App\Services\HealthSafety\FireExtinguisherService;
use App\Services\HealthSafety\HealthSafetySettings;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * One extinguisher: its three dates and computed state, the history of checks and services, and the actions on it. What
 * the user may do is decided by EquipmentScope on every call (the id is the only thing kept on the component, and a
 * call replayed after the unit left the user's reach is refused): managing needs manage_equipment, recording a check
 * needs record_checks or being the named responsible person.
 */
class ExtinguisherShow extends Component
{
    use EnforcesModuleAccess;
    use ScopesHealthSafetyByActor;
    use WithFileUploads;

    #[Locked]
    public int $extinguisherId;

    /** 'check', 'service', 'decommission' or '' (which form is open) */
    public string $panel = '';

    /** @var array<string, mixed> */
    public array $check = [];

    /** @var array<string, mixed> */
    public array $service = [];

    public ?TemporaryUploadedFile $certificate = null;

    public string $reason = '';

    public function mount(HsFireExtinguisher $extinguisher): void
    {
        $this->enforceLivewireModule(Permission::MODULE_HEALTH_SAFETY);
        $this->guardHealthSafetyPermission('health_safety.report_incident', 'health_safety.view_equipment');
        abort_unless($this->equipmentScope()->canView($this->actor(), $extinguisher), 403, 'This extinguisher is not available to you.');

        $this->extinguisherId = $extinguisher->id;
        $this->resetForms();

        // Arriving from a scanned QR label (?check=1): open the existing check form for whoever may record one.
        if (request()->boolean('check') && $extinguisher->status !== HsFireExtinguisher::STATUS_DECOMMISSIONED
            && $this->equipmentScope()->canCheck($this->actor(), $extinguisher)) {
            $this->panel = 'check';
        }
    }

    public function openPanel(string $panel): void
    {
        abort_unless(in_array($panel, ['check', 'service', 'decommission'], true), 422);

        $unit = $this->extinguisher();

        match ($panel) {
            'check' => abort_unless($this->equipmentScope()->canCheck($this->actor(), $unit), 403),
            default => abort_unless($this->equipmentScope()->canManage($this->actor(), $unit), 403),
        };

        $this->resetForms();
        $this->panel = $panel;
    }

    public function closePanel(): void
    {
        $this->resetForms();
    }

    public function recordCheck(FireExtinguisherService $service): void
    {
        $this->resetErrorBag();

        $this->validate([
            'check.checked_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'check.notes' => ['nullable', 'string', 'max:2000'],
        ], ['check.checked_on.before_or_equal' => 'The date cannot be in the future.']);

        $result = $service->recordCheck($this->extinguisher(), $this->actor(), $this->check);

        $this->resetForms();
        $this->dispatch('toast', type: $result->result === 'pass' ? 'success' : 'error', message: $result->result === 'pass' ? 'Check recorded: all points passed.' : 'Check recorded: something needs attention.');
    }

    public function recordService(FireExtinguisherService $service): void
    {
        $this->resetErrorBag();

        $maxKb = (int) config('gwl.hs_equipment_attachment_max_mb') * 1024;

        $this->validate([
            'service.serviced_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'service.service_type' => ['required', 'in:'.implode(',', array_keys(HsExtinguisherService::TYPES))],
            'service.vendor' => ['nullable', 'string', 'max:255'],
            'service.new_expiry_date' => ['nullable', 'date_format:Y-m-d'],
            'service.next_service_due' => ['nullable', 'date_format:Y-m-d'],
            'service.next_hydro_test_due' => ['nullable', 'date_format:Y-m-d'],
            'service.notes' => ['nullable', 'string', 'max:2000'],
            'certificate' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.$maxKb],
        ], ['service.serviced_on.before_or_equal' => 'The date cannot be in the future.']);

        $service->recordService($this->extinguisher(), $this->actor(), $this->service, $this->certificate);

        $this->resetForms();
        $this->dispatch('toast', type: 'success', message: 'Service recorded and the dates brought up to date.');
    }

    public function setStatus(string $status, FireExtinguisherService $service): void
    {
        $service->changeStatus($this->extinguisher(), $this->actor(), $status);
        $this->dispatch('toast', type: 'success', message: 'Status changed.');
    }

    public function decommission(FireExtinguisherService $service): void
    {
        $this->resetErrorBag();

        $service->decommission($this->extinguisher(), $this->actor(), $this->reason);

        $this->resetForms();
        $this->dispatch('toast', type: 'success', message: 'Extinguisher decommissioned.');
    }

    /** The unit, read afresh and checked against the viewer on every request. */
    protected function extinguisher(): HsFireExtinguisher
    {
        $unit = HsFireExtinguisher::query()->findOrFail($this->extinguisherId);

        abort_unless($this->equipmentScope()->canView($this->actor(), $unit), 403, 'This extinguisher is not available to you.');

        return $unit;
    }

    protected function resetForms(): void
    {
        $this->panel = '';
        $this->reason = '';
        $this->certificate = null;
        $this->resetErrorBag();

        $this->check = ['checked_on' => today()->toDateString(), 'notes' => ''];

        foreach (array_keys(HsFireExtinguisher::CHECK_POINTS) as $point) {
            $this->check[$point] = true;
        }

        $this->service = [
            'serviced_on' => today()->toDateString(), 'service_type' => HsExtinguisherService::TYPE_INSPECTION, 'vendor' => '',
            'new_expiry_date' => '', 'next_service_due' => '', 'next_hydro_test_due' => '', 'notes' => '',
        ];
    }

    public function render()
    {
        $unit = $this->extinguisher();
        $unit->load(['site', 'vehicle', 'district', 'region', 'responsible']);
        $scope = $this->equipmentScope();
        $user = $this->actor();
        $state = $unit->state();

        return view('livewire.health_safety.extinguisher-show', [
            'unit' => $unit,
            'state' => $state,
            'canManage' => $scope->canManage($user, $unit) && $unit->status !== HsFireExtinguisher::STATUS_DECOMMISSIONED,
            'canCheck' => $scope->canCheck($user, $unit) && $unit->status !== HsFireExtinguisher::STATUS_DECOMMISSIONED,
            // The named responsible person sees the unit and records checks on it, and nothing else: no service history.
            'fullView' => $scope->can($user, 'health_safety.view_equipment') && $scope->contains($user, $unit),
            'canSeeVehicle' => $scope->canSeeVehicle($user, $unit->vehicle),
            'checks' => $unit->checks()->with('checker')->orderByDesc('checked_on')->orderByDesc('id')->limit(30)->get(),
            'services' => $scope->can($user, 'health_safety.view_equipment') && $scope->contains($user, $unit)
                ? $unit->services()->with('creator')->orderByDesc('serviced_on')->orderByDesc('id')->limit(30)->get()
                : collect(),
            'checkPoints' => HsFireExtinguisher::CHECK_POINTS,
            'serviceTypes' => HsExtinguisherService::TYPES,
            'statuses' => HsFireExtinguisher::STATUSES,
            'nextCheckDue' => $unit->nextCheckDue(),
            'serviceMonths' => (int) HealthSafetySettings::value('hs_extinguisher_service_months'),
            'hydroYears' => (int) HealthSafetySettings::value('hs_extinguisher_hydro_years'),
            'maxMb' => (int) config('gwl.hs_equipment_attachment_max_mb'),
        ]);
    }
}
