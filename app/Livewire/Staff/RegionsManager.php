<?php

namespace App\Livewire\Staff;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\AuditLog;
use App\Models\Region;
use Livewire\Component;

class RegionsManager extends Component
{
    use EnforcesModuleAccess;

    public string $region_name = '';

    public ?int $editingId = null;

    public string $editingName = '';

    public function mount(): void
    {
        $this->enforceLivewireModule('staff');

        if (! $this->canManageRegions()) {
            abort(403);
        }
    }

    public function save(): void
    {
        $validated = $this->validate([
            'region_name' => ['required', 'string', 'max:255', 'unique:regions,region_name'],
        ]);

        $region = Region::create([
            'region_name' => $validated['region_name'],
        ]);

        AuditLog::record('create_region', 'staff', 'regions', $region->id, null, $region->toArray());

        $this->reset('region_name');
        session()->flash('success', 'Region created successfully.');
        $this->dispatch('toast', type: 'success', message: 'Region created successfully.');
    }

    public function edit(int $regionId): void
    {
        $region = Region::findOrFail($regionId);

        $this->editingId = $region->id;
        $this->editingName = $region->region_name;
    }

    public function cancelEdit(): void
    {
        $this->reset('editingId', 'editingName');
    }

    public function update(): void
    {
        $region = Region::findOrFail($this->editingId);

        $validated = $this->validate([
            'editingName' => ['required', 'string', 'max:255', 'unique:regions,region_name,'.$region->id],
        ]);

        $old = $region->toArray();
        $region->update([
            'region_name' => $validated['editingName'],
        ]);

        AuditLog::record('update_region', 'staff', 'regions', $region->id, $old, $region->fresh()->toArray());

        $this->reset('editingId', 'editingName');
        session()->flash('success', 'Region updated successfully.');
        $this->dispatch('toast', type: 'success', message: 'Region updated successfully.');
    }

    public function delete(int $regionId): void
    {
        $region = Region::withCount(['districts', 'employees'])->findOrFail($regionId);

        if ($region->districts_count > 0 || $region->employees_count > 0) {
            $this->addError('region_name', 'You cannot delete a region that still has locations or employees assigned.');
            $this->dispatch('toast', type: 'error', message: 'Region still has assigned data.');

            return;
        }

        $old = $region->toArray();
        $region->delete();

        AuditLog::record('delete_region', 'staff', 'regions', $regionId, $old, null);
        session()->flash('success', 'Region deleted successfully.');
        $this->dispatch('toast', type: 'success', message: 'Region deleted successfully.');
    }

    public function render()
    {
        return view('livewire.staff.regions-manager', [
            'regions' => Region::query()
                ->withCount(['districts', 'employees'])
                ->orderBy('region_name')
                ->get(),
        ]);
    }

    protected function canManageRegions(): bool
    {
        $user = auth()->user();

        return $user && ($user->hasRoles('super_admin') || $user->hasPermission('staff.manage_regions'));
    }
}
