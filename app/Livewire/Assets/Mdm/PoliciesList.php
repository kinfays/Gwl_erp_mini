<?php

namespace App\Livewire\Assets\Mdm;

use App\Livewire\Assets\Mdm\Concerns\AuthorizesMdm;
use App\Models\MdmPolicy;
use App\Models\Permission;
use App\Services\Assets\Mdm\PolicyService;
use App\Support\Audit;
use Livewire\Component;

class PoliciesList extends Component
{
    use AuthorizesMdm;

    public function mount(): void
    {
        $this->bootMdmScreen('assets.mdm_view');
    }

    /** A policy that still has phones on it cannot be deleted; an unpublished or unused one can. */
    public function delete(int $policyId): void
    {
        $this->authorizeMdm('assets.mdm_manage_policies');

        $policy = MdmPolicy::query()->withCount('devices')->findOrFail($policyId);

        if ($policy->devices_count > 0) {
            $this->dispatch('toast', type: 'error', message: 'This policy is still applied to '.$policy->devices_count.' phone(s), so it cannot be deleted.');

            return;
        }

        $name = $policy->name;
        $policy->delete();

        Audit::log('mdm_policy_deleted', Permission::MODULE_ASSETS, 'mdm_policies', $policyId, ['policy' => $name]);

        $this->dispatch('toast', type: 'success', message: 'Policy deleted. If it was published, remove it from the Google admin console too.');
    }

    public function render(PolicyService $policies)
    {
        $list = MdmPolicy::query()->with('apps')->withCount('devices')->orderBy('name')->get();

        return view('livewire.assets.mdm.policies-list', [
            'policies' => $list,
            'unpublished' => $list->mapWithKeys(fn (MdmPolicy $policy) => [$policy->id => $policies->hasUnpublishedChanges($policy)]),
            'canManage' => $this->canMdm('assets.mdm_manage_policies'),
        ]);
    }
}
