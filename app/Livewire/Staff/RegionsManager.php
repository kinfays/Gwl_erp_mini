<?php

namespace App\Livewire\Staff;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\AuditLog;
use App\Models\Region;
use Livewire\Component;

class RegionsManager extends Component
{
    use EnforcesModuleAccess;

    /** Uppercase letters/digits, 2-6 characters: what a letter's serial number starts with (GA-2026-001). */
    private const PREFIX_RULE = 'regex:/^[A-Z0-9]{2,6}$/';

    private const PREFIX_MESSAGE = 'Use 2 to 6 capital letters or digits, for example GA or ASH2.';

    public string $region_name = '';

    public string $letter_prefix = '';

    public ?int $editingId = null;

    public string $editingName = '';

    public string $editingPrefix = '';

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
            'letter_prefix' => ['nullable', 'string', self::PREFIX_RULE, 'unique:regions,letter_prefix'],
        ], [
            'letter_prefix.regex' => self::PREFIX_MESSAGE,
            'letter_prefix.unique' => 'Another region already uses this letter prefix.',
        ]);

        $region = Region::create([
            'region_name' => $validated['region_name'],
            'letter_prefix' => filled($validated['letter_prefix'] ?? null) ? $validated['letter_prefix'] : null,
        ]);

        // Left blank: derive it now (initials of the name, made unique) so the screen shows what letters will use.
        $region->assignLetterPrefix();

        AuditLog::record('create_region', 'staff', 'regions', $region->id, null, $region->fresh()->toArray());

        $this->reset('region_name', 'letter_prefix');
        session()->flash('success', 'Region created successfully.');
        $this->dispatch('toast', type: 'success', message: 'Region created successfully.');
    }

    public function edit(int $regionId): void
    {
        $region = Region::findOrFail($regionId);

        $this->editingId = $region->id;
        $this->editingName = $region->region_name;
        $this->editingPrefix = (string) $region->letter_prefix;
    }

    public function cancelEdit(): void
    {
        $this->reset('editingId', 'editingName', 'editingPrefix');
    }

    public function update(): void
    {
        $region = Region::findOrFail($this->editingId);

        // A region that has a prefix keeps one; only one that still has none (derived on its first letter) may stay blank.
        // The 2-6 character rule is for prefixes people type: an untouched derived one ("A" for Ashanti) must not block
        // renaming the region.
        $prefixRules = [$region->letter_prefix ? 'required' : 'nullable', 'string'];

        if ($this->editingPrefix !== (string) $region->letter_prefix) {
            $prefixRules[] = self::PREFIX_RULE;
            $prefixRules[] = 'unique:regions,letter_prefix,'.$region->id;
        }

        $validated = $this->validate([
            'editingName' => ['required', 'string', 'max:255', 'unique:regions,region_name,'.$region->id],
            'editingPrefix' => $prefixRules,
        ], [
            'editingPrefix.required' => 'A region needs a letter prefix; enter a new one rather than clearing it.',
            'editingPrefix.regex' => self::PREFIX_MESSAGE,
            'editingPrefix.unique' => 'Another region already uses this letter prefix.',
        ]);

        $old = $region->toArray();
        $region->update([
            'region_name' => $validated['editingName'],
            'letter_prefix' => filled($validated['editingPrefix'] ?? null) ? $validated['editingPrefix'] : $region->letter_prefix,
        ]);

        AuditLog::record('update_region', 'staff', 'regions', $region->id, $old, $region->fresh()->toArray());

        $this->reset('editingId', 'editingName', 'editingPrefix');
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
