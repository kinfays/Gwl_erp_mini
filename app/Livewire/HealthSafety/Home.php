<?php

namespace App\Livewire\HealthSafety;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\HealthSafety\Concerns\ScopesHealthSafetyByActor;
use App\Models\HsIncident;
use App\Models\HsIncidentAction;
use App\Models\HsPpeIssue;
use App\Models\Permission;
use App\Services\HealthSafety\EquipmentExpiryService;
use App\Services\HealthSafety\HealthSafetySettings;
use App\Services\HealthSafety\PpeComplianceService;
use App\Services\HealthSafety\PpeStockService;
use Livewire\Component;

/**
 * The overview for officers and managers: what is waiting for them, with each number linking to the rows behind it.
 * (Charts and rates come in Phase 4.) A plain employee has no overview and is sent to the report form by the route.
 */
class Home extends Component
{
    use EnforcesModuleAccess;
    use ScopesHealthSafetyByActor;

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_HEALTH_SAFETY);
        $this->guardHealthSafetyPermission('health_safety.view_dashboard', 'health_safety.view_incidents');
    }

    public function render()
    {
        $incidents = $this->incidentsForActor();
        $count = fn (array $statuses) => (clone $incidents)->whereIn('status', $statuses)->count();

        $overdueAcknowledgement = (clone $incidents)
            ->where('status', HsIncident::STATUS_REPORTED)
            ->where('hs_incidents.created_at', '<=', now()->subHours((int) HealthSafetySettings::value('hs_ack_hours')))
            ->count();

        $overdueActions = HsIncidentAction::query()
            ->where('status', HsIncidentAction::STATUS_OPEN)
            ->whereDate('due_on', '<', today())
            ->whereIn('incident_id', $this->visibility()->scopeEntitled(HsIncident::query(), $this->actor())->select('hs_incidents.id'))
            ->count();

        // The equipment row: each figure is the count of the very query the list it links to runs.
        $equipmentTiles = [];

        if ($this->actorCan('health_safety.view_equipment')) {
            $overview = app(EquipmentExpiryService::class)->overview($this->actor());
            $critical = (int) HealthSafetySettings::value('hs_expiry_critical_days');
            $definitions = [
                'extinguishers_expired' => ['Extinguishers expired', 'triangle-alert', 'danger', 'health_safety.extinguishers.index', 'Past their expiry date'],
                'extinguishers_expiring' => ['Extinguishers expiring', 'hourglass', 'warning', 'health_safety.extinguishers.index', 'Within '.$critical.' days'],
                'extinguishers_check_overdue' => ['Extinguisher checks overdue', 'clock', 'warning', 'health_safety.extinguishers.index', 'Not checked for '.(int) HealthSafetySettings::value('hs_check_interval_days').' days'],
                'kits_expired' => ['Kits with expired items', 'triangle-alert', 'danger', 'health_safety.kits.index', 'At least one item past its date'],
                'kits_expiring' => ['Kits with items expiring', 'hourglass', 'warning', 'health_safety.kits.index', 'Within '.$critical.' days'],
                'kits_check_overdue' => ['Kit checks overdue', 'clock', 'warning', 'health_safety.kits.index', 'Not checked for '.(int) HealthSafetySettings::value('hs_check_interval_days').' days'],
            ];

            foreach ($definitions as $key => [$label, $icon, $tone, $route, $meta]) {
                $equipmentTiles[] = [
                    'label' => $label,
                    'value' => $overview[$key]['count'],
                    'icon' => $icon,
                    'tone' => $overview[$key]['count'] > 0 ? $tone : 'muted',
                    'meta' => $meta,
                    'href' => route($route, $overview[$key]['params']),
                ];
            }
        }

        // The PPE row: low stock and the gaps come from the services the stock and gaps screens use; overdue replacements are
        // the very query the issues list runs for ?state=overdue.
        $ppeTiles = [];

        if ($this->actorCan('health_safety.view_equipment')) {
            $low = app(PpeStockService::class)->lowStock($this->actor())->count();
            $overdueIssues = $this->equipmentScope()->ppeIssues($this->actor())->withState(HsPpeIssue::STATE_OVERDUE)->count();
            $gapStaff = app(PpeComplianceService::class)->summary($this->actor())['gap_employees'];

            $ppeTiles = [
                ['label' => 'PPE low in stock', 'value' => $low, 'icon' => 'boxes', 'tone' => $low > 0 ? 'warning' : 'muted',
                    'meta' => 'At or below the reorder level', 'href' => route('health_safety.ppe.stock', ['low' => 1])],
                ['label' => 'PPE replacements overdue', 'value' => $overdueIssues, 'icon' => 'clock', 'tone' => $overdueIssues > 0 ? 'danger' : 'muted',
                    'meta' => 'Open issues past their replacement date', 'href' => route('health_safety.ppe.issues', ['state' => 'overdue'])],
                ['label' => 'Staff with PPE gaps', 'value' => $gapStaff, 'icon' => 'user-x', 'tone' => $gapStaff > 0 ? 'danger' : 'muted',
                    'meta' => 'Missing, overdue or short', 'href' => route('health_safety.ppe.gaps', ['state' => 'gap'])],
            ];
        }

        return view('livewire.health_safety.home', [
            'equipmentTiles' => $equipmentTiles,
            'ppeTiles' => $ppeTiles,
            'tiles' => [
                ['label' => 'New reports', 'value' => $count([HsIncident::STATUS_REPORTED]), 'icon' => 'inbox', 'tone' => 'warning',
                    'meta' => $overdueAcknowledgement > 0 ? $overdueAcknowledgement.' not acknowledged within '.HealthSafetySettings::value('hs_ack_hours').' hours' : 'None overdue',
                    'href' => route('health_safety.incidents', ['status' => HsIncident::STATUS_REPORTED])],
                ['label' => 'In progress', 'value' => $count([HsIncident::STATUS_ACKNOWLEDGED, HsIncident::STATUS_INVESTIGATING]), 'icon' => 'activity', 'tone' => 'primary',
                    'meta' => 'Acknowledged or being investigated',
                    'href' => route('health_safety.incidents', ['status' => 'in_progress'])],
                ['label' => 'Awaiting approval', 'value' => $count([HsIncident::STATUS_PENDING_CLOSURE]), 'icon' => 'clipboard-check', 'tone' => 'info',
                    'meta' => 'High and Critical incidents ready to close',
                    'href' => route('health_safety.incidents', ['status' => HsIncident::STATUS_PENDING_CLOSURE])],
                ['label' => 'Overdue actions', 'value' => $overdueActions, 'icon' => 'triangle-alert', 'tone' => $overdueActions > 0 ? 'danger' : 'muted',
                    'meta' => 'Open actions past their due date',
                    'href' => route('health_safety.actions', ['filter' => 'overdue'])],
            ],
            'recent' => (clone $incidents)->with(['region', 'district', 'department', 'site'])->latest('hs_incidents.created_at')->limit(8)->get(),
            'canSeeRegister' => $this->actorCan('health_safety.view_incidents'),
        ]);
    }
}
