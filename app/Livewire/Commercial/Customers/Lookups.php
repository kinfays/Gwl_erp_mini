<?php

namespace App\Livewire\Commercial\Customers;

use App\Livewire\Commercial\Concerns\ScopesCommercialByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\CommercialCustomerCategory;
use App\Models\Permission;
use App\Services\Commercial\Customers\CustomerCadence;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Settings that belong to the customer list rather than to a number: which group each category code belongs to (a PROPOSED
 * grouping until confirmed), what each account / meter status code means and whether it bills (unconfirmed meanings show as
 * their raw code), the codes found in files that nobody has reviewed yet, and how often each district is expected to upload.
 * Needs commercial.manage_settings. Every change is audited with the old and new value (no customer data is involved).
 */
class Lookups extends Component
{
    use EnforcesModuleAccess;
    use ScopesCommercialByActor;

    /** @var array<int, array{name: string, group: string, confirmed: bool}> */
    public array $categories = [];

    /** @var array<int, array{label: string, active: bool, billing: bool, confirmed: bool}> */
    public array $statuses = [];

    /** @var array<int, string> meter status id => label */
    public array $meters = [];

    /** @var array<int, string> district id => weekly | monthly | off | '' (the default) */
    public array $cadence = [];

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_COMMERCIAL);
        $this->guardCustomerList();
        $this->guardCommercialPermission('commercial.manage_settings');
        $this->load();
    }

    protected function load(): void
    {
        $this->categories = DB::table('commercial_customer_categories')->orderBy('code')->get()
            ->mapWithKeys(fn ($c) => [(int) $c->id => ['name' => (string) $c->name, 'group' => (string) $c->category_group, 'confirmed' => ! $c->group_is_proposed]])->all();
        $this->statuses = DB::table('commercial_customer_statuses')->orderBy('code')->get()
            ->mapWithKeys(fn ($s) => [(int) $s->id => ['label' => (string) $s->label, 'active' => (bool) $s->is_active, 'billing' => (bool) $s->is_billing, 'confirmed' => (bool) $s->meaning_confirmed]])->all();
        $this->meters = DB::table('commercial_meter_statuses')->orderBy('code')->pluck('label', 'id')->map(fn ($l) => (string) $l)->all();

        $cadence = app(CustomerCadence::class);
        $this->cadence = DB::table('districts')->orderBy('district_name')->pluck('id')->mapWithKeys(fn ($id) => [(int) $id => $cadence->isCustom((int) $id) ? $cadence->forDistrict((int) $id) : ''])->all();
    }

    public function saveCategories(): void
    {
        $this->guardCommercialPermission('commercial.manage_settings');
        $this->validate([
            'categories.*.name' => ['required', 'string', 'max:120'],
            'categories.*.group' => ['required', 'in:'.implode(',', array_keys(CommercialCustomerCategory::GROUPS))],
            'categories.*.confirmed' => ['boolean'],
        ]);

        $changes = [];

        foreach ($this->categories as $id => $row) {
            $before = DB::table('commercial_customer_categories')->where('id', $id)->first();

            if (! $before || ($before->name === $row['name'] && $before->category_group === $row['group'] && ! (bool) $before->group_is_proposed === (bool) $row['confirmed'])) {
                continue;
            }

            DB::table('commercial_customer_categories')->where('id', $id)->update([
                'name' => $row['name'], 'category_group' => $row['group'], 'group_is_proposed' => ! $row['confirmed'],
                // saving a pending code with a real group is the review
                'is_pending' => $before->is_pending && $row['group'] === 'unknown' && ! $row['confirmed'],
                'updated_at' => now(),
            ]);

            $changes[$before->code ?? 'UNKNOWN'] = ['group' => [$before->category_group, $row['group']], 'confirmed' => [! $before->group_is_proposed, (bool) $row['confirmed']]];
        }

        $this->done('commercial.customer_categories_changed', $changes);
    }

    public function saveStatuses(): void
    {
        $this->guardCommercialPermission('commercial.manage_settings');
        $this->validate([
            'statuses.*.label' => ['required', 'string', 'max:80'],
            'statuses.*.active' => ['boolean'], 'statuses.*.billing' => ['boolean'], 'statuses.*.confirmed' => ['boolean'],
            'meters.*' => ['required', 'string', 'max:80'],
        ]);

        $changes = [];

        foreach ($this->statuses as $id => $row) {
            $before = DB::table('commercial_customer_statuses')->where('id', $id)->first();

            if (! $before || ($before->label === $row['label'] && (bool) $before->is_active === (bool) $row['active'] && (bool) $before->is_billing === (bool) $row['billing'] && (bool) $before->meaning_confirmed === (bool) $row['confirmed'])) {
                continue;
            }

            DB::table('commercial_customer_statuses')->where('id', $id)->update([
                'label' => $row['label'], 'is_active' => (bool) $row['active'], 'is_billing' => (bool) $row['billing'], 'meaning_confirmed' => (bool) $row['confirmed'],
                'is_pending' => $before->is_pending && ! $row['confirmed'], 'updated_at' => now(),
            ]);

            $changes[$before->code] = ['label' => [$before->label, $row['label']], 'billing' => [(bool) $before->is_billing, (bool) $row['billing']], 'confirmed' => [(bool) $before->meaning_confirmed, (bool) $row['confirmed']]];
        }

        foreach ($this->meters as $id => $label) {
            $before = DB::table('commercial_meter_statuses')->where('id', $id)->first();

            if ($before && $before->label !== $label) {
                DB::table('commercial_meter_statuses')->where('id', $id)->update(['label' => $label, 'is_pending' => false, 'updated_at' => now()]);
                $changes['meter '.$before->code] = ['label' => [$before->label, $label]];
            }
        }

        $this->done('commercial.customer_statuses_changed', $changes);
    }

    public function saveCadence(CustomerCadence $cadence): void
    {
        $this->guardCommercialPermission('commercial.manage_settings');
        $this->validate(['cadence.*' => ['nullable', 'in:weekly,monthly,off,']]);

        foreach ($this->cadence as $districtId => $value) {
            $cadence->set((int) $districtId, $value === '' ? null : $value, auth()->id());
        }

        $this->load();
        $this->dispatch('toast', type: 'success', message: 'Upload cadence saved.');
    }

    /** @param  array<string, mixed>  $changes */
    protected function done(string $action, array $changes): void
    {
        if ($changes !== []) {
            Audit::log(action: $action, module: Permission::MODULE_COMMERCIAL, metadata: ['changes' => $changes]);
        }

        $this->load();
        $this->dispatch('toast', type: 'success', message: $changes === [] ? 'Nothing to save.' : count($changes).' saved.');
    }

    public function render()
    {
        return view('livewire.commercial.customers.lookups', [
            'codes' => DB::table('commercial_customer_categories')->pluck('code', 'id')->all(),
            'statusCodes' => DB::table('commercial_customer_statuses')->pluck('code', 'id')->all(),
            'pending' => DB::table('commercial_customer_categories')->where('is_pending', true)->count()
                + DB::table('commercial_customer_statuses')->where('is_pending', true)->count()
                + DB::table('commercial_meter_statuses')->where('is_pending', true)->count(),
            'pendingIds' => DB::table('commercial_customer_categories')->where('is_pending', true)->pluck('id')->all(),
            'meterCodes' => DB::table('commercial_meter_statuses')->pluck('code', 'id')->all(),
            'districts' => DB::table('districts')->orderBy('district_name')->pluck('district_name', 'id')->all(),
            'groups' => CommercialCustomerCategory::GROUPS,
            'options' => CustomerCadence::OPTIONS,
            'default' => CustomerCadence::OPTIONS[app(CustomerCadence::class)->default()],
        ]);
    }
}
