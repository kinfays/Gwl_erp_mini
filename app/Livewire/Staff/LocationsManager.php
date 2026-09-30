<?php

namespace App\Livewire\Staff;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\AuditLog;
use App\Models\District;
use App\Models\Region;
use App\Services\Staff\DistrictEmployeeSync;
use App\Support\ErpNavigation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;

class LocationsManager extends Component
{
    use EnforcesModuleAccess;

    public string $district_name = '';

    public int|string $region_id = '';

    public ?int $editingId = null;

    public string $editingName = '';

    public int|string $editingRegionId = '';

    public function mount(ErpNavigation $navigation): void
    {
        $this->enforceLivewireModule('staff');

        if (! $this->canManageLocations()) {
            abort(403);
        }
    }

    public function save(): void
    {
        $validated = $this->validate([
            'district_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('districts', 'district_name')
                    ->where(fn ($query) => $query->where('region_id', $this->region_id)),
            ],
            'region_id' => ['required', 'integer', 'exists:regions,id'],
        ]);

        $location = District::create($validated);

        AuditLog::record('create_location', 'staff', 'districts', $location->id, null, $location->toArray());

        $this->reset('district_name', 'region_id');
        session()->flash('success', 'Location created successfully.');
        $this->dispatch('toast', type: 'success', message: 'Location created successfully.');
    }

    public function edit(int $locationId): void
    {
        $location = District::findOrFail($locationId);

        $this->editingId = $location->id;
        $this->editingName = $location->district_name;
        $this->editingRegionId = $location->region_id;
    }

    public function cancelEdit(): void
    {
        $this->reset('editingId', 'editingName', 'editingRegionId');
    }

    public function update(): void
    {
        $location = District::findOrFail($this->editingId);

        $validated = $this->validate([
            'editingName' => [
                'required',
                'string',
                'max:255',
                Rule::unique('districts', 'district_name')
                    ->ignore($location->id)
                    ->where(fn ($query) => $query->where('region_id', $this->editingRegionId)),
            ],
            'editingRegionId' => ['required', 'integer', 'exists:regions,id'],
        ]);

        $old = $location->toArray();

        // One transaction: the district and its employees (location_type follows the name, region_id follows the
        // region) change together, including any Head Office roles removed from people no longer at Head Office.
        $employeesUpdated = DB::transaction(function () use ($location, $validated, $old) {
            $location->update([
                'district_name' => $validated['editingName'],
                'region_id' => $validated['editingRegionId'],
            ]);

            $employeesUpdated = app(DistrictEmployeeSync::class)->sync($location->fresh());

            AuditLog::record(
                'update_location',
                'staff',
                'districts',
                $location->id,
                $old,
                $location->fresh()->toArray(),
                ['employees_updated' => $employeesUpdated]
            );

            return $employeesUpdated;
        });

        $this->reset('editingId', 'editingName', 'editingRegionId');
        $message = 'Location updated successfully.'.($employeesUpdated > 0 ? " {$employeesUpdated} employee record(s) updated to match." : '');
        session()->flash('success', $message);
        $this->dispatch('toast', type: 'success', message: $message);
    }

    public function delete(int $locationId): void
    {
        $location = District::withCount('employees')->findOrFail($locationId);

        if ($location->employees_count > 0) {
            $this->addError('district_name', 'You cannot delete a location that still has employees assigned.');
            $this->dispatch('toast', type: 'error', message: 'Location still has employees assigned.');

            return;
        }

        $old = $location->toArray();
        $location->delete();

        AuditLog::record('delete_location', 'staff', 'districts', $locationId, $old, null);
        session()->flash('success', 'Location deleted successfully.');
        $this->dispatch('toast', type: 'success', message: 'Location deleted successfully.');
    }

    public function render()
    {
        return view('livewire.staff.locations-manager', [
            'locations' => District::query()
                ->with('region')
                ->withCount('employees')
                ->join('regions', 'regions.id', '=', 'districts.region_id')
                ->orderBy('regions.region_name')
                ->orderBy('districts.district_name')
                ->select('districts.*')
                ->get(),
            'regions' => Region::query()
                ->orderBy('region_name')
                ->get(),
        ]);
    }

    protected function canManageLocations(): bool
    {
        $user = auth()->user();

        return $user && ($user->hasRoles('super_admin') || $user->hasPermission('staff.manage_locations'));
    }
}
