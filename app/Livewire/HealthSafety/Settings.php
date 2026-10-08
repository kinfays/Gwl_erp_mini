<?php

namespace App\Livewire\HealthSafety;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\HealthSafety\Concerns\ScopesHealthSafetyByActor;
use App\Models\HsSetting;
use App\Models\Permission;
use App\Services\HealthSafety\HealthSafetySettings;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * The Health & Safety values the EHS department may tune (design 8.18 E), for whoever holds health_safety.manage_settings
 * (hs_manager and super_admin). A saved value overrides the default in config/gwl.php; "Reset" returns a group to the
 * defaults. Every change is audited with the old and new value by HealthSafetySettings.
 */
class Settings extends Component
{
    use EnforcesModuleAccess;
    use ScopesHealthSafetyByActor;

    /** @var array<string, mixed> form values keyed by setting name (lists are shown as comma separated text) */
    public array $values = [];

    public function mount(HealthSafetySettings $settings): void
    {
        $this->enforceLivewireModule(Permission::MODULE_HEALTH_SAFETY);
        $this->guardHealthSafetyPermission('health_safety.manage_settings');

        $this->load($settings);
    }

    public function save(HealthSafetySettings $settings): void
    {
        $this->guardHealthSafetyPermission('health_safety.manage_settings');
        $this->resetErrorBag();

        $submitted = [];

        foreach (HealthSafetySettings::definitions() as $key => $definition) {
            $value = $this->values[$key] ?? null;
            // An unticked box arrives as false or null.
            $submitted[$key] = $definition['type'] === 'bool' ? (bool) $value : $value;
        }

        try {
            $changes = $settings->save($submitted, $this->actor()->id);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $key => $messages) {
                $this->addError('values.'.$key, $messages[0]);
            }

            $this->dispatch('toast', type: 'error', message: 'Nothing was saved: check the highlighted settings.');

            return;
        }

        $this->load($settings);
        $this->dispatch('toast', type: 'success', message: $changes === [] ? 'No change to save.' : count($changes).' setting'.(count($changes) === 1 ? '' : 's').' saved.');
    }

    public function resetGroup(string $group, HealthSafetySettings $settings): void
    {
        $this->guardHealthSafetyPermission('health_safety.manage_settings');
        abort_unless(array_key_exists($group, HealthSafetySettings::GROUPS), 404);

        $keys = array_keys(array_filter(HealthSafetySettings::definitions(), fn (array $definition) => $definition['group'] === $group));
        $reset = $settings->reset($keys);

        $this->resetErrorBag();
        $this->load($settings);
        $this->dispatch('toast', type: 'success', message: $reset === [] ? 'Already at the defaults.' : 'Back to the defaults.');
    }

    public function resetAll(HealthSafetySettings $settings): void
    {
        $this->guardHealthSafetyPermission('health_safety.manage_settings');

        $reset = $settings->reset();

        $this->resetErrorBag();
        $this->load($settings);
        $this->dispatch('toast', type: 'success', message: $reset === [] ? 'Already at the defaults.' : 'Every setting is back to its default.');
    }

    protected function load(HealthSafetySettings $settings): void
    {
        $this->values = [];

        foreach (HealthSafetySettings::definitions() as $key => $definition) {
            $value = $settings->get($key);
            $this->values[$key] = $definition['type'] === 'intlist' ? HealthSafetySettings::listToText($value) : $value;
        }
    }

    public function render(HealthSafetySettings $settings)
    {
        $edited = HsSetting::query()->with('updater:id,full_name')->get()->keyBy('name');
        $groups = [];

        foreach (HealthSafetySettings::GROUPS as $group => $meta) {
            $fields = [];

            foreach (HealthSafetySettings::definitions() as $key => $definition) {
                if ($definition['group'] !== $group) {
                    continue;
                }

                $default = $settings->default($key);
                $fields[] = $definition + [
                    'key' => $key,
                    'default_text' => match ($definition['type']) {
                        'intlist' => HealthSafetySettings::listToText($default),
                        'bool' => $default ? 'On' : 'Off',
                        'text' => $default === '' ? 'empty' : $default,
                        default => $default.($definition['unit'] !== '' ? ' '.$definition['unit'] : ''),
                    },
                    'edited' => $edited->get($key),
                ];
            }

            $groups[$group] = $meta + ['fields' => $fields, 'any_edited' => collect($fields)->contains(fn ($field) => $field['edited'] !== null)];
        }

        return view('livewire.health_safety.settings', ['groups' => $groups]);
    }
}
