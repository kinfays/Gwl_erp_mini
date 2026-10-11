<?php

namespace App\Livewire\GWater;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\GWater\Concerns\ScopesGwaterByActor;
use App\Services\GWater\GwaterSettings;
use App\Services\GWater\GwaterWorkflowService;
use Livewire\Component;

/**
 * The location's own low-stock thresholds and reminder intervals. Needs gwater.manage_prices. A value saved equal to the
 * default is stored as no override, so it keeps following config/.env.
 */
class Settings extends Component
{
    use EnforcesModuleAccess;
    use ScopesGwaterByActor;

    /** @var array<string, string> */
    public array $values = [];

    /** The location the values were loaded for, so a change of location reloads them. */
    public ?int $loadedFor = null;

    public function mount(): void
    {
        $this->enforceGwater();
        $this->guard('gwater.manage_prices');
    }

    public function save(GwaterWorkflowService $workflow): void
    {
        $this->guard('gwater.manage_prices');

        $this->run(fn () => $workflow->saveSettings($this->actor(), $this->values, $this->recordingDistrict()), 'values');

        $this->loadedFor = null;
        $this->resetErrorBag();
        $this->dispatch('toast', type: 'success', message: 'Settings saved.');
    }

    public function resetToDefault(string $key, GwaterSettings $settings): void
    {
        $this->guard('gwater.manage_prices');

        $default = $settings->definitions()[$key]['default'] ?? abort(404);
        $this->values[$key] = (string) $default;
    }

    public function render(GwaterSettings $settings)
    {
        $location = $this->workingLocation();
        $all = $location ? $settings->all($location) : [];

        $marker = $location ? (int) $location['district_id'] : 0;

        if ($location && $this->loadedFor !== $marker) {
            $this->values = collect($all)->map(fn (array $row) => (string) $row['value'])->all();
            $this->loadedFor = $marker;
        }

        return view('livewire.gwater.settings', ['settings' => $all] + $this->locationView());
    }
}
