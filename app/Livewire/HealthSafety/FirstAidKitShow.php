<?php

namespace App\Livewire\HealthSafety;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\HealthSafety\Concerns\ScopesHealthSafetyByActor;
use App\Models\HsFirstAidKit;
use App\Models\Permission;
use App\Services\HealthSafety\FirstAidKitService;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One first aid kit: its contents against what it should hold, the check history and the actions on it. The id is the
 * only thing kept on the component and EquipmentScope is asked again on every call. Recording a check needs record_checks
 * or being the kit's named responsible person; everything else needs manage_equipment.
 */
class FirstAidKitShow extends Component
{
    use EnforcesModuleAccess;
    use ScopesHealthSafetyByActor;

    #[Locked]
    public int $kitId;

    /** 'check', 'contents', 'decommission' or '' (which form is open) */
    public string $panel = '';

    /** @var array<string, mixed> */
    public array $check = [];

    /** What the kit holds now, keyed by item id: ['current_qty' => ..., 'expiry_date' => ...]. @var array<int, array<string, mixed>> */
    public array $itemEdits = [];

    /** The contents list being edited. @var list<array<string, mixed>> */
    public array $contents = [];

    public string $reason = '';

    public function mount(HsFirstAidKit $kit): void
    {
        $this->enforceLivewireModule(Permission::MODULE_HEALTH_SAFETY);
        $this->guardHealthSafetyPermission('health_safety.report_incident', 'health_safety.view_equipment');
        abort_unless($this->equipmentScope()->canView($this->actor(), $kit), 403, 'This kit is not available to you.');

        $this->kitId = $kit->id;
        $this->resetForms();

        // Arriving from a scanned QR label (?check=1): open the existing check form for whoever may record one.
        if (request()->boolean('check') && $kit->status !== HsFirstAidKit::STATUS_DECOMMISSIONED
            && $this->equipmentScope()->canCheck($this->actor(), $kit)) {
            $this->panel = 'check';
        }
    }

    public function openPanel(string $panel): void
    {
        abort_unless(in_array($panel, ['check', 'contents', 'decommission'], true), 422);

        $kit = $this->kit();

        match ($panel) {
            'check' => abort_unless($this->equipmentScope()->canCheck($this->actor(), $kit), 403),
            default => abort_unless($this->equipmentScope()->canManage($this->actor(), $kit), 403),
        };

        $this->resetForms();
        $this->panel = $panel;
    }

    public function closePanel(): void
    {
        $this->resetForms();
    }

    public function addContentRow(): void
    {
        $this->contents[] = ['id' => null, 'item_name' => '', 'required_qty' => 1, 'has_expiry' => false];
    }

    public function removeContentRow(int $index): void
    {
        unset($this->contents[$index]);
        $this->contents = array_values($this->contents);
    }

    public function saveContents(FirstAidKitService $service): void
    {
        $this->resetErrorBag();

        $service->saveItems($this->kit(), $this->actor(), $this->contents);

        $this->resetForms();
        $this->dispatch('toast', type: 'success', message: 'Contents saved.');
    }

    public function recordCheck(FirstAidKitService $service): void
    {
        $this->resetErrorBag();

        $this->validate([
            'check.checked_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'check.notes' => ['nullable', 'string', 'max:2000'],
            'itemEdits.*.current_qty' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'itemEdits.*.expiry_date' => ['nullable', 'date_format:Y-m-d'],
        ], ['check.checked_on.before_or_equal' => 'The date cannot be in the future.']);

        $result = $service->recordCheck($this->kit(), $this->actor(), $this->check, $this->itemEdits);

        $this->resetForms();
        $this->dispatch('toast', type: $result->result === 'pass' ? 'success' : 'error', message: $result->result === 'pass' ? 'Check recorded: the kit is complete and in date.' : 'Check recorded: the kit needs attention.');
    }

    public function setMissing(bool $missing, FirstAidKitService $service): void
    {
        $service->setMissing($this->kit(), $this->actor(), $missing);
        $this->dispatch('toast', type: 'success', message: $missing ? 'Kit marked missing.' : 'Kit back in service.');
    }

    public function decommission(FirstAidKitService $service): void
    {
        $this->resetErrorBag();

        $service->decommission($this->kit(), $this->actor(), $this->reason);

        $this->resetForms();
        $this->dispatch('toast', type: 'success', message: 'Kit decommissioned.');
    }

    protected function kit(): HsFirstAidKit
    {
        $kit = HsFirstAidKit::query()->findOrFail($this->kitId);

        abort_unless($this->equipmentScope()->canView($this->actor(), $kit), 403, 'This kit is not available to you.');

        return $kit;
    }

    protected function resetForms(): void
    {
        $this->panel = '';
        $this->reason = '';
        $this->resetErrorBag();

        $kit = HsFirstAidKit::query()->with('items')->find($this->kitId);

        $this->check = ['checked_on' => today()->toDateString(), 'restocked' => false, 'notes' => ''];
        $this->itemEdits = $kit ? $kit->items->mapWithKeys(fn ($item) => [$item->id => [
            'current_qty' => $item->current_qty,
            'expiry_date' => $item->expiry_date?->toDateString() ?? '',
        ]])->all() : [];
        $this->contents = $kit ? $kit->items->map(fn ($item) => [
            'id' => $item->id, 'item_name' => $item->item_name, 'required_qty' => $item->required_qty, 'has_expiry' => $item->has_expiry,
        ])->all() : [];
    }

    public function render()
    {
        $kit = $this->kit();
        $kit->load(['site', 'vehicle', 'district', 'region', 'responsible', 'items']);
        $scope = $this->equipmentScope();
        $user = $this->actor();
        $state = $kit->state();
        $decommissioned = $kit->status === HsFirstAidKit::STATUS_DECOMMISSIONED;

        return view('livewire.health_safety.first-aid-kit-show', [
            'kit' => $kit,
            'state' => $state,
            'canManage' => $scope->canManage($user, $kit) && ! $decommissioned,
            'canCheck' => $scope->canCheck($user, $kit) && ! $decommissioned,
            'canSeeVehicle' => $scope->canSeeVehicle($user, $kit->vehicle),
            'checks' => $kit->checks()->with('checker')->orderByDesc('checked_on')->orderByDesc('id')->limit(30)->get(),
            'statuses' => HsFirstAidKit::STATUSES,
            'nextCheckDue' => $kit->nextCheckDue(),
        ]);
    }
}
