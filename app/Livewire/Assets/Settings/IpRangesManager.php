<?php

namespace App\Livewire\Assets\Settings;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\AuditLog;
use App\Models\District;
use App\Models\IctIpRange;
use App\Models\Permission;
use App\Models\Region;
use Livewire\Component;

class IpRangesManager extends Component
{
    use EnforcesModuleAccess;

    public string $label = '';

    public int|string $region_id = '';

    public int|string $district_id = '';

    public string $start_ip = '';

    public string $end_ip = '';

    public string $cidr = '';

    public string $notes = '';

    public ?int $editingId = null;

    public string $editingLabel = '';

    public int|string $editingRegionId = '';

    public int|string $editingDistrictId = '';

    public string $editingStartIp = '';

    public string $editingEndIp = '';

    public string $editingCidr = '';

    public string $editingNotes = '';

    public bool $editingIsActive = true;

    public function mount(): void
    {
        $this->enforceLivewireModule('assets');

        if (! $this->canManageIpRanges()) {
            abort(403);
        }
    }

    public function save(): void
    {
        $validated = $this->validate([
            'label' => ['required', 'string', 'max:255'],
            'region_id' => ['nullable', 'integer', 'exists:regions,id'],
            'district_id' => ['nullable', 'integer', 'exists:districts,id'],
            'start_ip' => ['required', 'ip'],
            'end_ip' => ['required', 'ip'],
            'cidr' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
        ]);

        $range = IctIpRange::create([
            ...$validated,
            'region_id' => $validated['region_id'] ?: null,
            'district_id' => $validated['district_id'] ?: null,
            'is_active' => true,
        ]);

        AuditLog::record('create_ip_range', Permission::MODULE_ASSETS, 'ict_ip_ranges', $range->id, null, $range->toArray());

        $this->reset('label', 'region_id', 'district_id', 'start_ip', 'end_ip', 'cidr', 'notes');
        $this->dispatch('toast', type: 'success', message: 'IP range created successfully.');
    }

    public function edit(int $rangeId): void
    {
        $range = IctIpRange::findOrFail($rangeId);

        $this->editingId = $range->id;
        $this->editingLabel = $range->label;
        $this->editingRegionId = $range->region_id ?? '';
        $this->editingDistrictId = $range->district_id ?? '';
        $this->editingStartIp = $range->start_ip;
        $this->editingEndIp = $range->end_ip;
        $this->editingCidr = (string) $range->cidr;
        $this->editingNotes = (string) $range->notes;
        $this->editingIsActive = (bool) $range->is_active;
    }

    public function cancelEdit(): void
    {
        $this->reset([
            'editingId', 'editingLabel', 'editingRegionId', 'editingDistrictId',
            'editingStartIp', 'editingEndIp', 'editingCidr', 'editingNotes', 'editingIsActive',
        ]);
    }

    public function update(): void
    {
        $range = IctIpRange::findOrFail($this->editingId);

        $validated = $this->validate([
            'editingLabel' => ['required', 'string', 'max:255'],
            'editingRegionId' => ['nullable', 'integer', 'exists:regions,id'],
            'editingDistrictId' => ['nullable', 'integer', 'exists:districts,id'],
            'editingStartIp' => ['required', 'ip'],
            'editingEndIp' => ['required', 'ip'],
            'editingCidr' => ['nullable', 'string', 'max:50'],
            'editingNotes' => ['nullable', 'string'],
        ]);

        $old = $range->toArray();
        $range->update([
            'label' => $validated['editingLabel'],
            'region_id' => $validated['editingRegionId'] ?: null,
            'district_id' => $validated['editingDistrictId'] ?: null,
            'start_ip' => $validated['editingStartIp'],
            'end_ip' => $validated['editingEndIp'],
            'cidr' => $validated['editingCidr'],
            'notes' => $validated['editingNotes'],
            'is_active' => $this->editingIsActive,
        ]);

        AuditLog::record('update_ip_range', Permission::MODULE_ASSETS, 'ict_ip_ranges', $range->id, $old, $range->fresh()->toArray());

        $this->cancelEdit();
        $this->dispatch('toast', type: 'success', message: 'IP range updated successfully.');
    }

    public function delete(int $rangeId): void
    {
        $range = IctIpRange::findOrFail($rangeId);
        $old = $range->toArray();
        $range->delete();

        AuditLog::record('delete_ip_range', Permission::MODULE_ASSETS, 'ict_ip_ranges', $rangeId, $old, null);
        $this->dispatch('toast', type: 'success', message: 'IP range deleted successfully.');
    }

    protected function canManageIpRanges(): bool
    {
        $user = auth()->user();

        return $user && ($user->hasRoles('super_admin') || $user->hasPermission('assets.manage_ip_ranges'));
    }

    public function render()
    {
        return view('livewire.assets.settings.ip-ranges-manager', [
            'ranges' => IctIpRange::query()
                ->with(['region', 'district'])
                ->orderBy('label')
                ->get(),
            'regions' => Region::query()->orderBy('region_name')->get(),
            'districts' => District::query()->orderBy('district_name')->get(),
        ]);
    }
}
