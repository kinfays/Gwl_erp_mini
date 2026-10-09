<?php

namespace Tests\Feature\HealthSafety;

use App\Models\HsFireExtinguisher;
use App\Models\HsFirstAidKit;
use App\Models\HsIncident;
use App\Models\HsIncidentAttachment;
use App\Models\HsPpeIssue;
use App\Models\HsPpeStockMovement;
use App\Models\User;
use App\Services\HealthSafety\EquipmentScope;
use App\Services\HealthSafety\IncidentVisibility;
use App\Services\HealthSafety\PpeComplianceService;
use Database\Seeders\Demo\HealthSafetyDemoSeeder;
use Illuminate\Support\Facades\DB;

/**
 * The Health & Safety demo seeder: it runs, it can be run again without adding anything, it produces every state the
 * module computes, and what it makes is seen only as far as IncidentVisibility and EquipmentScope allow.
 */
class HealthSafetyDemoSeederTest extends HealthSafetyTestCase
{
    /** @return array<string, int> */
    private function counts(): array
    {
        $tables = ['hs_sites', 'hs_incidents', 'hs_incident_actions', 'hs_incident_persons', 'hs_incident_attachments', 'hs_incident_status_logs',
            'hs_fire_extinguishers', 'hs_extinguisher_checks', 'hs_extinguisher_services', 'hs_first_aid_kits', 'hs_first_aid_kit_items', 'hs_first_aid_kit_checks',
            'hs_first_aid_item_templates', 'hs_ppe_types', 'hs_ppe_entitlements', 'hs_ppe_reorder_levels', 'hs_ppe_stock_movements', 'hs_ppe_issues',
            'employees', 'users', 'vehicles', 'regions', 'districts'];

        return collect($tables)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all();
    }

    private function login(string $staffId): User
    {
        return User::query()->where('staff_id', $staffId)->firstOrFail();
    }

    public function test_it_runs_twice_without_adding_anything(): void
    {
        $this->seed(HealthSafetyDemoSeeder::class);
        $first = $this->counts();

        $this->assertSame(60, $first['hs_incidents']);
        $this->assertSame(40, $first['hs_fire_extinguishers']);
        $this->assertSame(25, $first['hs_first_aid_kits']);

        $this->seed(HealthSafetyDemoSeeder::class);

        $this->assertSame($first, $this->counts(), 'a second run changes nothing');
    }

    public function test_it_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        (new HealthSafetyDemoSeeder)->run();

        $this->assertSame(0, HsIncident::query()->count());
        $this->assertSame(0, User::query()->where('staff_id', 'HSA001')->count());
    }

    public function test_every_state_the_module_computes_appears(): void
    {
        $this->seed(HealthSafetyDemoSeeder::class);

        $extinguishers = HsFireExtinguisher::query()->get()->map->state()->countBy();

        foreach (array_keys(HsFireExtinguisher::states()) as $state) {
            $this->assertGreaterThanOrEqual(2, $extinguishers[$state] ?? 0, "extinguisher state {$state}");
        }

        $this->assertGreaterThanOrEqual(2, $extinguishers[HsFireExtinguisher::STATE_OK] ?? 0);

        $kits = HsFirstAidKit::query()->with('items')->get()->map->state()->countBy();

        foreach (array_keys(HsFirstAidKit::states()) as $state) {
            $this->assertGreaterThanOrEqual(2, $kits[$state] ?? 0, "kit state {$state}");
        }

        $this->assertGreaterThanOrEqual(2, $kits[HsFirstAidKit::STATE_OK] ?? 0);

        $summary = app(PpeComplianceService::class)->summary($this->login('HSSA01'));

        foreach (array_keys(PpeComplianceService::STATES) as $state) {
            $this->assertGreaterThan(0, $summary['by_state'][$state], "PPE compliance state {$state}");
        }

        // The stock is a ledger and never goes below zero; at least one line is below its reorder level.
        $this->assertSame(0, DB::table('hs_ppe_stock_movements')->select('site_id', 'ppe_type_id', 'size', DB::raw('sum(quantity) as balance'))->groupBy('site_id', 'ppe_type_id', 'size')->get()->where('balance', '<', 0)->count());
        $this->assertGreaterThan(0, HsPpeStockMovement::query()->where('movement_type', 'adjustment')->count());
        $this->assertGreaterThan(0, HsPpeIssue::query()->where('is_historic', true)->count());
        $this->assertGreaterThan(0, HsPpeIssue::query()->whereNotNull('replaced_by_issue_id')->count());
    }

    public function test_the_incidents_cover_the_lifecycle_and_the_special_kinds(): void
    {
        $this->seed(HealthSafetyDemoSeeder::class);

        foreach (['reported', 'acknowledged', 'investigating', 'pending_closure', 'closed', 'cancelled'] as $status) {
            $this->assertGreaterThan(0, HsIncident::query()->where('status', $status)->count(), $status);
        }

        foreach (['low', 'medium', 'high', 'critical'] as $severity) {
            $this->assertGreaterThan(0, HsIncident::query()->where('severity', $severity)->count(), $severity);
        }

        $this->assertSame(6, HsIncident::query()->distinct()->count('incident_type'));
        $this->assertSame(4, HsIncident::query()->distinct()->count('context'));
        $this->assertGreaterThan(0, HsIncident::query()->where('is_confidential', true)->count());
        $this->assertGreaterThan(0, HsIncident::query()->whereNotNull('recorded_by_user_id')->count());
        $this->assertGreaterThan(0, DB::table('hs_incident_persons')->count(), 'injury details');
        $this->assertGreaterThan(0, HsIncidentAttachment::query()->count(), 'photos');
        $this->assertGreaterThan(0, HsIncident::query()->where('status', 'reported')->where('created_at', '<=', now()->subHours(24))->count(), 'overdue acknowledgement');
        $this->assertGreaterThan(0, HsIncident::query()->whereIn('status', ['acknowledged', 'investigating'])->where('acknowledged_at', '<', today()->subDays(14))->count(), 'overdue investigation');
        $this->assertGreaterThan(0, DB::table('hs_incident_actions')->where('status', 'open')->whereDate('due_on', '<', today())->count(), 'overdue action');
    }

    public function test_anonymous_reports_store_nobody(): void
    {
        $this->seed(HealthSafetyDemoSeeder::class);

        $anonymous = HsIncident::query()->where('is_anonymous', true)->get();
        $this->assertCount(4, $anonymous);

        foreach ($anonymous as $incident) {
            $this->assertNull($incident->reported_by_user_id);
            $this->assertNull($incident->reported_by_employee_id);
            $this->assertNull($incident->recorded_by_user_id);
            $this->assertNull($incident->reporter_name_raw);
            $this->assertFalse((bool) $incident->is_confidential);
            $this->assertSame(0, $incident->statusLogs()->whereNotNull('user_id')->where('id', $incident->statusLogs()->min('id'))->count(), 'the first timeline entry names nobody');
            $this->assertSame([], HsIncidentAttachment::query()->where('incident_id', $incident->id)->whereNotNull('uploaded_by')->pluck('uploaded_by')->all());

            $audit = DB::table('audit_logs')->where('action', 'health_safety.incident_reported')->where('metadata', 'like', '%'.$incident->reference.'%')->first();
            $this->assertNotNull($audit);
            $this->assertNull($audit->user_id);
            $this->assertNull($audit->ip_address);
        }
    }

    public function test_each_person_sees_only_what_visibility_and_scope_allow(): void
    {
        $this->seed(HealthSafetyDemoSeeder::class);

        $visibility = app(IncidentVisibility::class);
        $equipment = app(EquipmentScope::class);

        // Officer (Accra West): their own region's incidents and equipment, nothing from the other regions.
        $officer = $this->login('HSA001');
        $region = $officer->employee->region_id;
        $this->assertSame([$region], $visibility->scopeFor(HsIncident::query(), $officer)->pluck('region_id')->unique()->values()->all());
        $this->assertSame([$region], $equipment->extinguishers($officer)->pluck('region_id')->unique()->values()->all());
        $this->assertSame([$region], $equipment->kits($officer)->pluck('region_id')->unique()->values()->all());
        $this->assertLessThan(HsIncident::query()->count(), $visibility->scopeFor(HsIncident::query(), $officer)->count());

        // The Health & Safety manager sees every region.
        $this->assertSame(60, $visibility->scopeFor(HsIncident::query(), $this->login('HSA002'))->count());
        $this->assertSame(40, $equipment->extinguishers($this->login('HSA002'))->count());

        // District manager: their own district (plus anything they reported themselves), and only that district's equipment.
        $manager = $this->login('HSA004');
        $districtId = $manager->employee->district_id;
        $seen = $visibility->scopeFor(HsIncident::query(), $manager)->get();
        $this->assertTrue($seen->every(fn (HsIncident $incident) => (int) $incident->district_id === (int) $districtId || (int) $incident->reported_by_user_id === (int) $manager->id || (int) $incident->recorded_by_user_id === (int) $manager->id));
        $this->assertSame([$districtId], $equipment->extinguishers($manager)->pluck('district_id')->unique()->filter()->values()->all());
        $this->assertLessThan($visibility->scopeFor(HsIncident::query(), $officer)->count(), $seen->count());

        // A plain reporter sees only what they filed themselves, and no equipment register.
        $reporter = $this->login('HSA201');
        $own = $visibility->scopeFor(HsIncident::query(), $reporter)->get();
        $this->assertTrue($own->every(fn (HsIncident $incident) => (int) $incident->reported_by_user_id === (int) $reporter->id || (int) $incident->recorded_by_user_id === (int) $reporter->id));
        $this->assertSame(0, $equipment->extinguishers($reporter)->count());
        $this->assertFalse($equipment->can($reporter, 'health_safety.view_equipment'));

        // A confidential report is hidden from an officer of another region and from the reporter's district manager.
        $confidential = HsIncident::query()->where('is_confidential', true)->first();
        $other = collect(['HSA001', 'HSK001', 'HSW001'])->map(fn ($id) => $this->login($id))->first(fn (User $user) => (int) $user->employee->region_id !== (int) $confidential->region_id);
        $this->assertFalse($visibility->canView($other, $confidential));
    }

    public function test_the_logins_use_the_default_password(): void
    {
        $this->seed(HealthSafetyDemoSeeder::class);

        foreach (['HSA001' => 'hs_officer', 'HSA002' => 'hs_manager', 'HSA003' => 'regional_chief_manager', 'HSA004' => 'district_manager', 'HSSA01' => 'super_admin'] as $staffId => $role) {
            $user = $this->login($staffId);

            $this->assertTrue(password_verify('12345', $user->password), $staffId);
            $this->assertFalse((bool) $user->must_change_password, $staffId);
            $this->assertTrue($user->hasRoles($role), $staffId);
        }
    }
}
