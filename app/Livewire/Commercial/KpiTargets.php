<?php

namespace App\Livewire\Commercial;

use App\Livewire\Commercial\Concerns\ScopesCommercialByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\CommercialDistrictTarget;
use App\Models\District;
use App\Models\Permission;
use App\Services\Commercial\CommercialKpiService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Where the monthly district targets (sales, billing, collection) are set, by holders of commercial.manage_settings. A target applies
 * from a month until a later one replaces it, so setting a new year's targets never changes an old month's efficiency. Only the
 * districts of regions the person may see are offered, and every save is re-checked against that.
 */
class KpiTargets extends Component
{
    use EnforcesModuleAccess;
    use ScopesCommercialByActor;

    /** The month the values typed below start to apply (Y-m). */
    public string $from = '';

    /** @var array<int, array<string, string>> district id => metric => typed value */
    public array $grid = [];

    /** The district whose history is shown. */
    public string $historyDistrict = '';

    public function mount(CommercialKpiService $kpis): void
    {
        $this->enforceLivewireModule(Permission::MODULE_COMMERCIAL);
        $this->guardCommercialPermission('commercial.manage_settings');
        $this->from = now()->format('Y-m');
        $this->loadGrid($kpis);
    }

    public function updatedFrom(): void
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->from)) {
            $this->loadGrid(app(CommercialKpiService::class));
        }
    }

    /** @return \Illuminate\Support\Collection<int, District> */
    protected function districts()
    {
        return District::query()->when(! $this->actorSeesAllRegions(), fn ($q) => $q->where('region_id', $this->actorRegionId() ?? 0))
            ->where('district_name', 'not like', '%Head Office%')->where('district_name', 'not like', '%Regional Office%')->orderBy('district_name')->get(['id', 'district_name', 'region_id']);
    }

    /** Fills the grid with the targets in force in the chosen month. */
    protected function loadGrid(CommercialKpiService $kpis): void
    {
        $districts = $this->districts();
        $current = $kpis->current($districts->pluck('id')->all(), $this->from.'-01');
        $this->grid = [];

        foreach ($districts as $district) {
            foreach (array_keys(CommercialKpiService::METRICS) as $metric) {
                $this->grid[$district->id][$metric] = isset($current[$district->id][$metric]) ? (string) $current[$district->id][$metric][0] : '';
            }
        }
    }

    public function save(CommercialKpiService $kpis): void
    {
        $this->guardCommercialPermission('commercial.manage_settings');
        $this->resetErrorBag();

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->from)) {
            $this->addError('from', 'Choose the month the targets start.');

            return;
        }

        $districts = $this->districts()->keyBy('id');
        $current = $kpis->current($districts->keys()->all(), $this->from.'-01');
        $saved = 0;
        $failed = false;

        foreach ($this->grid as $districtId => $metrics) {
            if (! $districts->has((int) $districtId)) {
                abort(403);   // not a district of this person's region
            }

            foreach ($metrics as $metric => $value) {
                $value = trim(str_replace(',', '', (string) $value));

                if ($value === '' || ! isset(CommercialKpiService::METRICS[$metric])) {
                    continue;
                }

                if (isset($current[$districtId][$metric]) && is_numeric($value) && abs($current[$districtId][$metric][0] - (float) $value) < 0.005) {
                    continue;   // unchanged
                }

                try {
                    $kpis->save(Auth::user(), (int) $districtId, $metric, $this->from, $value);
                    $saved++;
                } catch (ValidationException $e) {
                    $failed = true;
                    $this->addError('grid.'.$districtId.'.'.$metric, (string) collect($e->errors())->flatten()->first());
                }
            }
        }

        if (! $failed) {
            $this->loadGrid($kpis);
        }

        if ($failed) {
            $this->dispatch('toast', type: 'error', message: 'Some targets were not saved: check the highlighted boxes.');
        } elseif ($saved > 0) {
            $this->dispatch('toast', type: 'success', message: $saved.' target'.($saved === 1 ? '' : 's').' saved from '.Carbon::parse($this->from.'-01')->format('F Y').'.');
        } else {
            $this->dispatch('toast', type: 'info', message: 'Nothing changed.');
        }
    }

    public function delete(int $id, CommercialKpiService $kpis): void
    {
        $this->guardCommercialPermission('commercial.manage_settings');

        $target = CommercialDistrictTarget::query()->findOrFail($id);
        abort_unless($this->districts()->contains('id', $target->district_id), 404);

        $kpis->delete(Auth::user(), $id);
        $this->loadGrid($kpis);
        $this->dispatch('toast', type: 'success', message: 'That target row was removed.');
    }

    public function render(CommercialKpiService $kpis)
    {
        $this->guardCommercialPermission('commercial.manage_settings');

        $districts = $this->districts();
        $history = ctype_digit($this->historyDistrict) && $districts->contains('id', (int) $this->historyDistrict) ? $kpis->history((int) $this->historyDistrict) : collect();

        return view('livewire.commercial.kpi-targets', ['districts' => $districts, 'metrics' => CommercialKpiService::METRICS, 'history' => $history]);
    }
}
