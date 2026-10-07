<?php

namespace App\Livewire\Commercial;

use App\Livewire\Commercial\Concerns\ScopesCommercialByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\CommercialSetting;
use App\Models\Permission;
use App\Services\Commercial\BillingAnalyticsService;
use App\Services\Commercial\CommercialReportData;
use App\Services\Commercial\CommercialSettings;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * The Commercial targets and thresholds, editable by whoever holds commercial.manage_settings (super_admin only by
 * default). Saved values are overrides of config/gwl.php; "Reset" puts a group back to the defaults. Every change is
 * audited with the old and new value.
 */
class Settings extends Component
{
    use EnforcesModuleAccess;
    use ScopesCommercialByActor;

    /** @var array<string, mixed> form values, keyed by the setting with dots written as "__" (wire:model cannot hold dots) */
    public array $values = [];

    public function mount(CommercialSettings $settings): void
    {
        $this->enforceLivewireModule(Permission::MODULE_COMMERCIAL);
        $this->guardCommercialPermission('commercial.manage_settings');

        $this->load($settings);
    }

    public function save(CommercialSettings $settings): void
    {
        $this->guardCommercialPermission('commercial.manage_settings');
        $this->resetErrorBag();

        $submitted = [];

        foreach (array_keys(CommercialSettings::definitions()) as $key) {
            $submitted[$key] = $this->values[$this->field($key)] ?? null;
        }

        // An unticked box arrives as false/null; everything else is the typed value.
        foreach (CommercialSettings::definitions() as $key => $definition) {
            if ($definition['type'] === 'bool') {
                $submitted[$key] = (bool) $submitted[$key];
            }
        }

        try {
            $changes = $settings->save($submitted, auth()->id());
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $key => $messages) {
                $this->addError('values.'.$this->field($key), $messages[0]);
            }

            $this->dispatch('toast', type: 'error', message: 'Nothing was saved: check the highlighted settings.');

            return;
        }

        $this->load($settings);
        $this->dispatch('toast', type: 'success', message: $changes === [] ? 'No change to save.' : count($changes).' setting'.(count($changes) === 1 ? '' : 's').' saved.');
    }

    public function resetGroup(string $group, CommercialSettings $settings): void
    {
        $this->guardCommercialPermission('commercial.manage_settings');
        abort_unless(array_key_exists($group, CommercialSettings::GROUPS), 404);

        $keys = array_keys(array_filter(CommercialSettings::definitions(), fn (array $definition) => $definition['group'] === $group));
        $reset = $settings->reset($keys);

        $this->resetErrorBag();
        $this->load($settings);
        $this->dispatch('toast', type: 'success', message: $reset === [] ? 'Already at the defaults.' : 'Back to the defaults.');
    }

    public function resetAll(CommercialSettings $settings): void
    {
        $this->guardCommercialPermission('commercial.manage_settings');

        $reset = $settings->reset();

        $this->resetErrorBag();
        $this->load($settings);
        $this->dispatch('toast', type: 'success', message: $reset === [] ? 'Already at the defaults.' : 'Every setting is back to its default.');
    }

    protected function load(CommercialSettings $settings): void
    {
        $this->values = [];

        foreach (array_keys(CommercialSettings::definitions()) as $key) {
            $this->values[$this->field($key)] = $settings->current($key);
        }
    }

    protected function field(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    /**
     * "What would these exception thresholds flag?" against the latest billing snapshot in the user's own scope, from the
     * values currently typed in the form (a value that is not valid yet falls back to the one in force), next to what is
     * flagged now. Nothing is saved.
     *
     * @return array<string, mixed>|null
     */
    protected function exceptionPreview(CommercialReportData $data, BillingAnalyticsService $billing): ?array
    {
        $snapshot = $data->snapshot();

        if (! $snapshot) {
            return null;
        }

        $routes = $data->snapshotRoutes($snapshot['id']);
        $typed = [];

        foreach ([
            'min_customers' => 'commercial_exception_min_customers',
            'high_unbilled_pct' => 'commercial_exception_high_unbilled_pct',
            'high_estimation_pct' => 'commercial_exception_high_estimation_pct',
            'credit_amount' => 'commercial_exception_credit_amount',
        ] as $name => $key) {
            $definition = CommercialSettings::definitions()[$key];
            $value = $this->values[$this->field($key)] ?? null;

            if (is_numeric($value) && $value >= $definition['min'] && $value <= $definition['max'] && ($definition['type'] !== 'int' || (float) $value == (int) $value)) {
                $typed[$name] = $definition['type'] === 'int' ? (int) $value : (float) $value;
            }
        }

        return [
            'label' => $snapshot['label'],
            'total' => $routes->count(),
            'with' => count($billing->exceptions($routes, $typed)['rows']),
            'now' => count($billing->exceptions($routes)['rows']),
        ];
    }

    public function render(CommercialSettings $settings, CommercialReportData $data, BillingAnalyticsService $billing)
    {
        $edited = CommercialSetting::query()->with('updater:id,full_name')->get()->keyBy('name');
        $groups = [];

        foreach (CommercialSettings::GROUPS as $group => $meta) {
            $fields = [];

            foreach (CommercialSettings::definitions() as $key => $definition) {
                if ($definition['group'] !== $group) {
                    continue;
                }

                $fields[] = [
                    'key' => $key,
                    'field' => $this->field($key),
                    'default' => $settings->default($key),
                    'edited' => $edited->get($key),
                    ...$definition,
                ];
            }

            $groups[$group] = [...$meta, 'fields' => $fields, 'any_edited' => collect($fields)->contains(fn (array $field) => $field['edited'] !== null)];
        }

        return view('livewire.commercial.settings', ['groups' => $groups, 'preview' => $this->exceptionPreview($data, $billing)]);
    }
}
