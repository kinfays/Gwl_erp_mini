<?php

namespace App\Livewire\Assets\Settings;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\AuditLog;
use App\Models\IctAsset;
use App\Models\IctAssetReplacementPolicy;
use App\Models\Permission;
use Illuminate\Validation\Rule;
use Livewire\Component;

class ReplacementPolicyManager extends Component
{
    use EnforcesModuleAccess;

    public int|string $defaultYears = '';

    public string $overrideType = '';

    public int|string $overrideYears = '';

    public function mount(): void
    {
        $this->enforceLivewireModule('assets');

        if (! $this->canManage()) {
            abort(403);
        }

        $this->defaultYears = IctAssetReplacementPolicy::resolve()['default'];
    }

    public function saveDefault(): void
    {
        $this->authorizeManage();

        $validated = $this->validate(['defaultYears' => ['required', 'integer', 'min:1', 'max:50']]);

        // Update the one NULL row; never create a second (the unique index cannot stop duplicate NULLs).
        $policy = IctAssetReplacementPolicy::query()->whereNull('asset_type')->first();
        $old = $policy?->toArray();

        $policy
            ? $policy->update(['years' => $validated['defaultYears']])
            : $policy = IctAssetReplacementPolicy::query()->create(['asset_type' => null, 'years' => $validated['defaultYears']]);

        AuditLog::record(
            $old ? 'update_replacement_policy' : 'create_replacement_policy',
            Permission::MODULE_ASSETS,
            'ict_asset_replacement_policies',
            $policy->id,
            $old,
            $policy->fresh()->toArray(),
        );

        $this->dispatch('toast', type: 'success', message: 'Default replacement policy saved.');
    }

    public function addOverride(): void
    {
        $this->authorizeManage();

        $validated = $this->validate([
            'overrideType' => ['required', Rule::in($this->assetTypeKeys()), Rule::unique('ict_asset_replacement_policies', 'asset_type')],
            'overrideYears' => ['required', 'integer', 'min:1', 'max:50'],
        ]);

        $policy = IctAssetReplacementPolicy::query()->create([
            'asset_type' => $validated['overrideType'],
            'years' => $validated['overrideYears'],
        ]);

        AuditLog::record('create_replacement_policy', Permission::MODULE_ASSETS, 'ict_asset_replacement_policies', $policy->id, null, $policy->toArray());

        $this->reset('overrideType', 'overrideYears');
        $this->dispatch('toast', type: 'success', message: 'Override added.');
    }

    public function updateOverride(int $policyId, int|string $years): void
    {
        $this->authorizeManage();

        $policy = IctAssetReplacementPolicy::query()->whereNotNull('asset_type')->findOrFail($policyId);

        validator(['years' => $years], ['years' => ['required', 'integer', 'min:1', 'max:50']])->validate();

        $old = $policy->toArray();
        $policy->update(['years' => (int) $years]);

        AuditLog::record('update_replacement_policy', Permission::MODULE_ASSETS, 'ict_asset_replacement_policies', $policy->id, $old, $policy->fresh()->toArray());

        $this->dispatch('toast', type: 'success', message: 'Override updated.');
    }

    public function deleteOverride(int $policyId): void
    {
        $this->authorizeManage();

        $policy = IctAssetReplacementPolicy::query()->whereNotNull('asset_type')->findOrFail($policyId);
        $old = $policy->toArray();
        $policy->delete();

        AuditLog::record('delete_replacement_policy', Permission::MODULE_ASSETS, 'ict_asset_replacement_policies', $policyId, $old, null);

        $this->dispatch('toast', type: 'success', message: 'Override removed; that type follows the default again.');
    }

    /** @return array<string, string> asset_type => label across all three categories */
    protected function assetTypeLabels(): array
    {
        return array_merge(...array_values(IctAsset::ASSET_TYPES));
    }

    protected function assetTypeKeys(): array
    {
        return array_keys($this->assetTypeLabels());
    }

    protected function canManage(): bool
    {
        $user = auth()->user();

        return $user && ($user->hasRoles('super_admin') || $user->hasPermission('assets.manage_replacement_policy'));
    }

    protected function authorizeManage(): void
    {
        abort_unless($this->canManage(), 403);
    }

    public function render()
    {
        return view('livewire.assets.settings.replacement-policy-manager', [
            'overrides' => IctAssetReplacementPolicy::query()->whereNotNull('asset_type')->orderBy('asset_type')->get(),
            'assetTypes' => $this->assetTypeLabels(),
        ]);
    }
}
