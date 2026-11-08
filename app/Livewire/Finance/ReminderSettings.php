<?php

namespace App\Livewire\Finance;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\Finance\Concerns\ScopesFinanceByActor;
use App\Models\Permission;
use App\Services\Finance\AuditSettings;
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\ModuleReminderSettings;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A module's reminder settings for ONE location: Finance > Settings > Reminders (finance.manage_reminders) or Audit > Settings >
 * Reminders (audit.manage_reminders). Two independent sets: this one screen serves both doors, but every read, every save and the
 * permission are the door's own (a Finance manager cannot open or change the Audit set and the other way round; super_admin may do
 * both for the location they pick). The module is fixed by the route and locked against the browser. Written only by the settings
 * service (FinanceSettings / AuditSettings::save()).
 */
class ReminderSettings extends Component
{
    use EnforcesModuleAccess;
    use ScopesFinanceByActor;

    /** 'finance' or 'audit': which set this screen edits. Set once by the route; the browser cannot change it. */
    #[Locked]
    public string $module = 'finance';

    /** @var array<string, mixed> the typed values by setting key (a switch is a bool) */
    public array $values = [];

    public function mount(string $module = 'finance'): void
    {
        $this->module = $module === 'audit' ? 'audit' : 'finance';
        $this->enforceFinance($this->module === 'audit' ? Permission::MODULE_AUDIT : Permission::MODULE_FINANCE);
        $this->guard($this->module.'.manage_reminders');
        $this->loadValues();
    }

    public function updatedLocationId(): void
    {
        $this->loadValues();
    }

    public function save(): void
    {
        $this->guard($this->module.'.manage_reminders');
        $this->resetErrorBag();

        $this->run(fn () => $this->settings()->save($this->actor(), $this->values, $this->recordingDistrict()), 'values');
        $this->loadValues();
        $this->dispatch('toast', type: 'success', message: 'Settings saved.');
    }

    public function resetToDefaults(): void
    {
        $this->guard($this->module.'.manage_reminders');
        $this->resetErrorBag();

        $settings = $this->settings();
        $this->run(fn () => $settings->save($this->actor(), collect($settings->definitions())->map(fn (array $definition) => $definition['default'])->all(), $this->recordingDistrict()), 'values');
        $this->loadValues();
        $this->dispatch('toast', type: 'success', message: 'Back to the standard values.');
    }

    protected function settings(): ModuleReminderSettings
    {
        return $this->module === 'audit' ? app(AuditSettings::class) : app(FinanceSettings::class);
    }

    protected function loadValues(): void
    {
        $location = $this->workingLocation();
        $this->values = $location
            ? collect($this->settings()->all($location))->map(fn (array $row) => $row['type'] === 'bool' ? (bool) $row['value'] : (string) $row['value'])->all()
            : [];
    }

    public function render()
    {
        $location = $this->workingLocation();

        return view('livewire.finance.reminder-settings', [
            'rows' => $location ? $this->settings()->all($location) : [],
            'canManage' => $this->can($this->module.'.manage_reminders'),
            'title' => $this->module === 'audit' ? 'Audit reminders' : 'Finance reminders',
            'blurb' => $this->module === 'audit'
                ? 'When Audit officers of your location are reminded about vouchers waiting for a tick or a query.'
                : 'When Finance officers of your location are reminded about queried vouchers, vouchers Audit has ticked, and drafts nobody has sent.',
        ] + $this->locationView());
    }
}
