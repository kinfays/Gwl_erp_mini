<?php

namespace App\Livewire\Commercial\Sms;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\Permission;
use App\Services\Commercial\CommercialSettings;
use App\Services\Commercial\Sms\SmsAccess;
use App\Support\Audit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * SMS limits and defaults, for holders of commercial.manage_sms_settings (super_admin only). They are overrides of config/gwl.php
 * stored by CommercialSettings, like the other Commercial settings. The provider key, the sender IDs and the region lock are NOT
 * here: they live in the server's .env, and this screen never shows the key.
 */
class Settings extends Component
{
    use EnforcesModuleAccess;

    /** @var array<string, mixed> */
    public array $values = [];

    public function mount(CommercialSettings $settings): void
    {
        $this->enforceLivewireModule(Permission::MODULE_COMMERCIAL);
        SmsAccess::authorize(Auth::user(), SmsAccess::SETTINGS);
        $this->load($settings);
    }

    protected function keys(): array
    {
        return array_keys(array_filter(CommercialSettings::definitions(), fn (array $definition) => $definition['group'] === 'sms'));
    }

    protected function load(CommercialSettings $settings): void
    {
        $this->values = [];

        foreach ($this->keys() as $key) {
            $this->values[$key] = $settings->current($key);
        }
    }

    public function save(CommercialSettings $settings): void
    {
        SmsAccess::authorize(Auth::user(), SmsAccess::SETTINGS);
        $this->resetErrorBag();

        $submitted = [];

        foreach ($this->keys() as $key) {
            $submitted[$key] = $this->values[$key] ?? null;
        }

        try {
            $changes = $settings->save($submitted, Auth::id());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $this->addError('values.'.$key, $messages[0]);
            }

            $this->dispatch('toast', type: 'error', message: 'Nothing was saved: check the highlighted settings.');

            return;
        }

        if ($changes !== []) {
            Audit::log('commercial.sms_settings_changed', Permission::MODULE_COMMERCIAL, null, null, ['changes' => $changes]);
        }

        $this->load($settings);
        $this->dispatch('toast', type: 'success', message: $changes === [] ? 'No change to save.' : count($changes).' setting(s) saved.');
    }

    public function resetAll(CommercialSettings $settings): void
    {
        SmsAccess::authorize(Auth::user(), SmsAccess::SETTINGS);

        $reset = $settings->reset($this->keys());
        $this->load($settings);
        $this->dispatch('toast', type: 'success', message: $reset === [] ? 'Already at the defaults.' : 'Back to the defaults.');
    }

    public function render(CommercialSettings $settings)
    {
        SmsAccess::authorize(Auth::user(), SmsAccess::SETTINGS);

        $fields = [];

        foreach ($this->keys() as $key) {
            $fields[] = ['key' => $key, 'default' => $settings->default($key), 'edited' => $settings->isOverridden($key), ...CommercialSettings::definitions()[$key]];
        }

        return view('livewire.commercial.sms.settings', [
            'fields' => $fields,
            'regions' => implode(', ', array_map('mb_strtoupper', (array) config('gwl.commercial_sms_region_names'))),
            'senders' => implode(', ', (array) config('gwl.arkesel.sender_ids')),
            'keySet' => (string) config('gwl.arkesel.api_key') !== '',
            'webhook' => (string) config('gwl.arkesel.webhook_token') !== '',
        ]);
    }
}
