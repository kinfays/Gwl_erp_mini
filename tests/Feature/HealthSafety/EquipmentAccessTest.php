<?php

namespace Tests\Feature\HealthSafety;

use App\Livewire\HealthSafety\ExtinguisherForm;
use App\Livewire\HealthSafety\ExtinguisherShow;
use App\Livewire\HealthSafety\Extinguishers;
use App\Livewire\HealthSafety\FirstAidKitForm;
use App\Livewire\HealthSafety\FirstAidKits;
use App\Livewire\HealthSafety\FirstAidKitShow;
use App\Livewire\HealthSafety\Home;
use App\Livewire\HealthSafety\KitTemplates;
use App\Livewire\HealthSafety\MyEquipment;
use App\Models\HsExtinguisherService;
use App\Models\HsFireExtinguisher;
use App\Models\HsFirstAidItemTemplate;
use App\Models\HsFirstAidKit;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\ErpNavigation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

class EquipmentAccessTest extends HealthSafetyTestCase
{
    private function pass(array $overrides = []): array
    {
        return ['in_place' => true, 'accessible' => true, 'seal_intact' => true, 'pressure_ok' => true, 'no_damage' => true, 'signage_ok' => true, ...$overrides];
    }

    // ---------------------------------------------------------------- scope

    public function test_a_regional_officer_cannot_see_edit_or_work_on_another_regions_equipment(): void
    {
        $officer = $this->officer('200001', $this->accraWest, $this->odorkor);
        $mine = $this->unit(['asset_code' => 'ACCRA-1'], $this->site('Accra Site'));
        $theirs = $this->unit(['asset_code' => 'KUMASI-1'], $this->site('Kumasi Site', $this->kumasi));
        $theirKit = $this->kit(['asset_code' => 'KUMASI-KIT'], [], $this->site('Kumasi Kit Site', $this->kumasi));

        Livewire::actingAs($officer)->test(Extinguishers::class)->assertSee('ACCRA-1')->assertDontSee('KUMASI-1');
        Livewire::actingAs($officer)->test(FirstAidKits::class)->assertDontSee('KUMASI-KIT');

        $this->actingAs($officer)->get(route('health_safety.extinguishers.show', $mine))->assertOk();
        $this->actingAs($officer)->get(route('health_safety.extinguishers.show', $theirs))->assertForbidden();
        $this->actingAs($officer)->get(route('health_safety.extinguishers.edit', $theirs))->assertForbidden();
        $this->actingAs($officer)->get(route('health_safety.kits.show', $theirKit))->assertForbidden();
        $this->actingAs($officer)->get(route('health_safety.kits.edit', $theirKit))->assertForbidden();

        Livewire::actingAs($officer)->test(ExtinguisherShow::class, ['extinguisher' => $theirs])->assertForbidden();
        Livewire::actingAs($officer)->test(ExtinguisherForm::class, ['extinguisher' => $theirs])->assertForbidden();

        // A call replayed after the unit moved out of reach is refused.
        $page = Livewire::actingAs($officer)->test(ExtinguisherShow::class, ['extinguisher' => $mine]);
        $mine->update(['region_id' => $this->ashanti->id]);
        $page->call('setStatus', 'discharged')->assertForbidden();
        $this->assertSame('in_service', $mine->fresh()->status);
    }

    public function test_a_district_manager_sees_only_their_district_and_cannot_manage(): void
    {
        $manager = $this->districtManager('200003', $this->sowutuom);
        $inDistrict = $this->unit(['asset_code' => 'SOW-1'], $this->site('Sowutuom Site'));
        $elsewhere = $this->unit(['asset_code' => 'ODO-1'], $this->site('Odorkor Site', $this->odorkor));

        Livewire::actingAs($manager)->test(Extinguishers::class)->assertSee('SOW-1')->assertDontSee('ODO-1');

        $this->actingAs($manager)->get(route('health_safety.extinguishers.show', $inDistrict))->assertOk();
        $this->actingAs($manager)->get(route('health_safety.extinguishers.show', $elsewhere))->assertForbidden();

        // view_equipment and record_checks, but not manage_equipment.
        $this->actingAs($manager)->get(route('health_safety.extinguishers.create'))->assertForbidden();
        $this->actingAs($manager)->get(route('health_safety.extinguishers.edit', $inDistrict))->assertForbidden();
        $this->actingAs($manager)->get(route('health_safety.equipment-import'))->assertForbidden();
        $this->actingAs($manager)->get(route('health_safety.kit-templates'))->assertForbidden();
    }

    public function test_head_office_staff_the_manager_and_super_admin_see_every_region(): void
    {
        $this->unit(['asset_code' => 'ACCRA-1'], $this->site('Accra Site'));
        $this->unit(['asset_code' => 'KUMASI-1'], $this->site('Kumasi Site', $this->kumasi));

        foreach ([$this->officer('200020', $this->accraWest, $this->headOffice), $this->hsManager(), $this->superAdmin()] as $viewer) {
            Livewire::actingAs($viewer)->test(Extinguishers::class)->assertSee('ACCRA-1')->assertSee('KUMASI-1');
        }
    }

    // ---------------------------------------------------------------- permissions

    public function test_a_user_without_view_equipment_gets_403_on_the_lists(): void
    {
        $employee = $this->reporter();

        foreach (['health_safety.extinguishers.index', 'health_safety.kits.index', 'health_safety.extinguishers.create', 'health_safety.kits.create', 'health_safety.kit-templates', 'health_safety.equipment-import'] as $route) {
            $this->actingAs($employee)->get(route($route))->assertForbidden();
        }

        Livewire::actingAs($employee)->test(Extinguishers::class)->assertForbidden();
        Livewire::actingAs($employee)->test(FirstAidKits::class)->assertForbidden();
        Livewire::actingAs($employee)->test(ExtinguisherForm::class)->assertForbidden();
        Livewire::actingAs($employee)->test(KitTemplates::class)->assertForbidden();

        // And cannot open an item that is not theirs.
        $unit = $this->unit();
        $this->actingAs($employee)->get(route('health_safety.extinguishers.show', $unit))->assertForbidden();
        Livewire::actingAs($employee)->test(ExtinguisherShow::class, ['extinguisher' => $unit])->assertForbidden();
    }

    public function test_record_checks_can_record_but_not_edit_service_or_decommission(): void
    {
        $manager = $this->districtManager('200003', $this->sowutuom);
        $unit = $this->unit(['expiry_date' => today()->addYear()->toDateString()], $this->site('Sowutuom Site'));

        $page = Livewire::actingAs($manager)->test(ExtinguisherShow::class, ['extinguisher' => $unit]);

        $page->call('openPanel', 'check')->set('check', ['checked_on' => today()->toDateString(), ...$this->pass(), 'notes' => ''])->call('recordCheck')->assertHasNoErrors();
        $this->assertSame(1, $unit->checks()->count());

        // (A fresh component for each refused call: Livewire cannot carry on after a 403.)
        foreach ([['openPanel', ['service']], ['openPanel', ['decommission']], ['setStatus', ['discharged']], ['decommission', []]] as [$method, $arguments]) {
            Livewire::actingAs($manager)->test(ExtinguisherShow::class, ['extinguisher' => $unit])->call($method, ...$arguments)->assertForbidden();
        }

        $this->assertSame('in_service', $unit->fresh()->status);
        $this->assertSame(0, $unit->services()->count());

        // Nor can they add or change a kit.
        $kit = $this->kit([], [['Gauze', 2, 2, null]], $this->site('Sowutuom Kit Site'));
        Livewire::actingAs($manager)->test(FirstAidKitShow::class, ['kit' => $kit])->call('openPanel', 'contents')->assertForbidden();
    }

    public function test_the_named_responsible_person_can_check_their_own_item_but_not_another_and_nothing_else(): void
    {
        $officer = $this->officer();
        $kofi = $this->userWithRoles('300001', ['employee']);
        $mine = $this->unit(['responsible_employee_id' => $kofi->employee->id, 'expiry_date' => today()->addYear()->toDateString()]);
        $other = $this->unit();
        $myKit = $this->kit(['responsible_employee_id' => $kofi->employee->id], [['Gauze', 2, 2, null]]);

        // They reach their item without any equipment permission.
        $this->assertFalse($kofi->hasPermission('health_safety.view_equipment'));
        $this->actingAs($kofi)->get(route('health_safety.extinguishers.show', $mine))->assertOk();
        $this->actingAs($kofi)->get(route('health_safety.kits.show', $myKit))->assertOk();
        $this->actingAs($kofi)->get(route('health_safety.extinguishers.show', $other))->assertForbidden();

        $page = Livewire::actingAs($kofi)->test(ExtinguisherShow::class, ['extinguisher' => $mine]);
        $page->call('openPanel', 'check')->set('check', ['checked_on' => today()->toDateString(), ...$this->pass(['pressure_ok' => false]), 'notes' => 'Needle in the red.'])->call('recordCheck')->assertHasNoErrors();

        $this->assertSame('fail', $mine->fresh()->last_check_result);
        $this->assertSame($kofi->id, $mine->checks()->first()->checked_by);

        // Not another item, not a service, not a status change, not an edit, not a decommission.
        Livewire::actingAs($kofi)->test(ExtinguisherShow::class, ['extinguisher' => $other])->assertForbidden();
        Livewire::actingAs($kofi)->test(ExtinguisherShow::class, ['extinguisher' => $mine])->call('openPanel', 'service')->assertForbidden();
        Livewire::actingAs($kofi)->test(ExtinguisherShow::class, ['extinguisher' => $mine])->call('setStatus', 'discharged')->assertForbidden();
        $this->actingAs($kofi)->get(route('health_safety.extinguishers.edit', $mine))->assertForbidden();
        $this->actingAs($kofi)->get(route('health_safety.kits.edit', $myKit))->assertForbidden();
        $this->assertSame(0, $mine->services()->count());
        $this->assertSame('in_service', $mine->fresh()->status);

        // Their kit check works through the screen too.
        Livewire::actingAs($kofi)->test(FirstAidKitShow::class, ['kit' => $myKit])
            ->call('openPanel', 'check')->call('recordCheck')->assertHasNoErrors();
        $this->assertSame(1, $myKit->checks()->count());

        // The service layer agrees, whatever the screen did.
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(\App\Services\HealthSafety\FireExtinguisherService::class)->recordCheck($other, $kofi, ['checked_on' => today()->toDateString(), ...$this->pass()]);
        $this->assertNotNull($officer);
    }

    public function test_the_responsible_person_sees_their_items_on_my_equipment_and_in_the_sidebar(): void
    {
        $kofi = $this->userWithRoles('300001', ['employee']);
        $navigation = app(ErpNavigation::class);
        $labels = fn ($user) => collect($navigation->build($user, 'health_safety')['sidebar'])->pluck('label')->all();

        $this->assertNotContains('My equipment', $labels($kofi));

        $this->unit(['asset_code' => 'MINE-1', 'responsible_employee_id' => $kofi->employee->id]);
        $this->unit(['asset_code' => 'NOT-MINE-1']);

        $this->assertContains('My equipment', $labels($kofi->fresh()));
        $this->actingAs($kofi)->get(route('health_safety.my-equipment'))->assertOk();
        Livewire::actingAs($kofi)->test(MyEquipment::class)->assertSee('MINE-1')->assertDontSee('NOT-MINE-1');
    }

    public function test_the_responsible_person_does_not_see_the_service_history_or_certificates(): void
    {
        $officer = $this->officer();
        $kofi = $this->userWithRoles('300001', ['employee']);
        $unit = $this->unit(['responsible_employee_id' => $kofi->employee->id]);
        $service = app(\App\Services\HealthSafety\FireExtinguisherService::class)->recordService($unit, $officer, ['serviced_on' => today()->toDateString(), 'service_type' => 'inspection', 'vendor' => 'SECRET-VENDOR'], UploadedFile::fake()->create('cert.pdf', 20, 'application/pdf'));

        Livewire::actingAs($officer)->test(ExtinguisherShow::class, ['extinguisher' => $unit])->assertSee('SECRET-VENDOR');
        Livewire::actingAs($kofi)->test(ExtinguisherShow::class, ['extinguisher' => $unit])->assertDontSee('SECRET-VENDOR')->assertDontSee('Service history');

        $this->actingAs($kofi)->get(route('health_safety.equipment-files.show', $service))->assertForbidden();
    }

    // ---------------------------------------------------------------- certificates

    public function test_a_certificate_download_is_authorised(): void
    {
        $officer = $this->officer();
        $unit = $this->unit();
        $service = app(\App\Services\HealthSafety\FireExtinguisherService::class)->recordService($unit, $officer, ['serviced_on' => today()->toDateString(), 'service_type' => 'inspection'], UploadedFile::fake()->create('cert.pdf', 20, 'application/pdf'));

        $response = $this->actingAs($officer)->get(route('health_safety.equipment-files.show', $service));
        $response->assertOk();
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $this->actingAs($this->hsManager())->get(route('health_safety.equipment-files.show', $service))->assertOk();
        $this->actingAs($this->districtManager('200003', $this->sowutuom))->get(route('health_safety.equipment-files.show', $service))->assertOk();

        $this->actingAs($this->officer('200050', $this->ashanti, $this->kumasi))->get(route('health_safety.equipment-files.show', $service))->assertForbidden();
        $this->actingAs($this->reporter())->get(route('health_safety.equipment-files.show', $service))->assertForbidden();

        // No public URL, and a missing file is a 404.
        Storage::disk('public')->assertMissing($service->certificate_path);
        Storage::disk('local')->delete($service->certificate_path);
        $this->actingAs($officer)->get(route('health_safety.equipment-files.show', $service))->assertNotFound();
    }

    // ---------------------------------------------------------------- screens

    public function test_an_officer_adds_an_extinguisher_through_the_form_with_prefilled_dates(): void
    {
        config(['gwl.hs_extinguisher_service_months' => 12, 'gwl.hs_extinguisher_hydro_years' => 5]);
        $officer = $this->officer();
        $site = $this->site('Sowutuom Office');

        $component = Livewire::actingAs($officer)->test(ExtinguisherForm::class)
            ->set('extinguisherType', 'co2')
            ->set('siteId', $site->id)
            ->set('expiryDate', today()->addYear()->toDateString())
            ->set('lastServicedOn', '2026-03-01')
            ->set('lastHydroTestOn', '2026-03-01');

        $this->assertSame('2027-03-01', $component->get('nextServiceDue'), 'pre-filled 12 months on');
        $this->assertSame('2031-03-01', $component->get('nextHydroTestDue'), 'pre-filled 5 years on, and editable');

        $component->set('nextHydroTestDue', '2029-03-01')->call('save')->assertHasNoErrors();

        $unit = HsFireExtinguisher::query()->firstOrFail();
        $this->assertSame('co2', $unit->extinguisher_type);
        $this->assertSame($site->id, $unit->site_id);
        $this->assertSame('2029-03-01', $unit->next_hydro_test_due->toDateString());
        $this->assertMatchesRegularExpression('/^FE-/', $unit->asset_code);
    }

    public function test_the_form_needs_a_type_and_a_place_and_rejects_a_duplicate_code(): void
    {
        $officer = $this->officer();
        $this->unit(['asset_code' => 'TAKEN-1']);

        Livewire::actingAs($officer)->test(ExtinguisherForm::class)->call('save')->assertHasErrors(['extinguisherType']);

        Livewire::actingAs($officer)->test(ExtinguisherForm::class)
            ->set('extinguisherType', 'water')
            ->call('save')
            ->assertHasErrors(['location']);

        Livewire::actingAs($officer)->test(ExtinguisherForm::class)
            ->set('extinguisherType', 'water')->set('siteId', $this->site('Another')->id)->set('assetCode', 'TAKEN-1')
            ->call('save')
            ->assertHasErrors(['asset_code']);

        $this->assertSame(1, HsFireExtinguisher::query()->count());
    }

    public function test_a_unit_can_be_placed_in_a_vehicle_from_the_form(): void
    {
        $officer = $this->officer();
        $vehicle = Vehicle::query()->create(['type' => 'pickup', 'brand' => 'Toyota', 'model' => 'Hilux', 'number_plate' => 'GW 777-24', 'status' => 'active']);

        Livewire::actingAs($officer)->test(ExtinguisherForm::class)
            ->set('extinguisherType', 'dry_powder')
            ->set('locationMode', 'vehicle')
            ->set('vehicleSearch', 'GW 777')
            ->assertSee('GW 777-24')
            ->call('chooseVehicle', $vehicle->id)
            ->call('save')
            ->assertHasNoErrors();

        $unit = HsFireExtinguisher::query()->firstOrFail();
        $this->assertSame($vehicle->id, $unit->vehicle_id);
        $this->assertNull($unit->site_id);
        $this->assertSame($this->accraWest->id, $unit->region_id);
    }

    public function test_the_vehicle_is_linked_only_to_someone_who_may_see_it(): void
    {
        $vehicle = Vehicle::query()->create(['type' => 'pickup', 'brand' => 'Toyota', 'model' => 'Hilux', 'number_plate' => 'GW 777-24', 'status' => 'active']);
        $unit = $this->unit(['site_id' => null, 'vehicle_id' => $vehicle->id]);
        $vehiclesUrl = route('transport.vehicles');

        $plain = $this->officer();
        Livewire::actingAs($plain)->test(ExtinguisherShow::class, ['extinguisher' => $unit])
            ->assertSee('GW 777-24')
            ->assertDontSee($vehiclesUrl, false);

        $fleet = $this->userWithRoles('200060', ['employee', 'hs_officer', 'transport_manager']);
        Livewire::actingAs($fleet)->test(ExtinguisherShow::class, ['extinguisher' => $unit])
            ->assertSee('GW 777-24')
            ->assertSee($vehiclesUrl, false);

        $this->actingAs($plain)->get(route('health_safety.extinguishers.index'))->assertSee('GW 777-24');
    }

    public function test_filters_and_the_urls_the_overview_links_to_work(): void
    {
        $officer = $this->officer();
        $this->unit(['asset_code' => 'EXPIRED-1', 'expiry_date' => today()->subDay()->toDateString()]);
        $this->unit(['asset_code' => 'SOON-1', 'expiry_date' => today()->addDays(10)->toDateString()]);
        $this->unit(['asset_code' => 'FINE-1', 'expiry_date' => today()->addYear()->toDateString()]);

        $this->actingAs($officer)->get(route('health_safety.extinguishers.index', ['state' => 'expired']))
            ->assertOk()->assertSee('EXPIRED-1')->assertDontSee('SOON-1')->assertDontSee('FINE-1');

        $this->actingAs($officer)->get(route('health_safety.extinguishers.index', ['expiring' => 30]))
            ->assertOk()->assertSee('SOON-1')->assertDontSee('EXPIRED-1')->assertDontSee('FINE-1');

        // A state or window that means nothing is dropped rather than breaking the page.
        $this->actingAs($officer)->get(route('health_safety.extinguishers.index', ['state' => 'bogus', 'expiring' => 'soon']))
            ->assertOk()->assertSee('EXPIRED-1')->assertSee('FINE-1');

        Livewire::actingAs($officer)->test(Extinguishers::class)
            ->set('search', 'SOON')->assertSee('SOON-1')->assertDontSee('FINE-1')
            ->set('search', '')->call('sortByExpiry')->assertSet('sort', '-expiry');
    }

    public function test_a_check_form_failure_is_shown_and_nothing_is_saved(): void
    {
        $officer = $this->officer();
        $unit = $this->unit();

        Livewire::actingAs($officer)->test(ExtinguisherShow::class, ['extinguisher' => $unit])
            ->call('openPanel', 'check')
            ->set('check.seal_intact', false)
            ->call('recordCheck')
            ->assertHasErrors(['notes']);

        $this->assertSame(0, $unit->checks()->count());
    }

    public function test_the_officer_records_a_service_with_a_certificate_through_the_screen(): void
    {
        $officer = $this->officer();
        $unit = $this->unit(['status' => 'out_for_service', 'expiry_date' => today()->addDays(5)->toDateString()]);

        Livewire::actingAs($officer)->test(ExtinguisherShow::class, ['extinguisher' => $unit])
            ->call('openPanel', 'service')
            ->set('service.service_type', 'recharge')
            ->set('service.vendor', 'FireSafe Ltd')
            ->set('service.new_expiry_date', today()->addYears(2)->toDateString())
            ->set('certificate', UploadedFile::fake()->create('cert.pdf', 20, 'application/pdf'))
            ->call('recordService')
            ->assertHasNoErrors();

        $unit = $unit->fresh();
        $this->assertSame('in_service', $unit->status);
        $this->assertSame(today()->addYears(2)->toDateString(), $unit->expiry_date->toDateString());
        $this->assertNotNull(HsExtinguisherService::query()->firstOrFail()->certificate_path);
    }

    public function test_kit_forms_and_screens_work_end_to_end(): void
    {
        $officer = $this->officer();
        $site = $this->site('Kit Office');

        // The kit screens say so when there are no templates.
        Livewire::actingAs($officer)->test(FirstAidKitForm::class)->assertSee('No kit templates exist yet');
        Livewire::actingAs($officer)->test(FirstAidKits::class)->assertSee('No kit templates exist yet');

        Livewire::actingAs($officer)->test(KitTemplates::class)
            ->set('kitType', 'small')
            ->call('loadStarterItems')
            ->assertSee('Confirm the contents')
            ->assertSee('These are suggestions')
            ->assertSet('starterLoaded', true);

        // Loading the starter items fills the editable list only: nothing is saved.
        $this->assertSame(0, HsFirstAidItemTemplate::query()->count());

        Livewire::actingAs($officer)->test(KitTemplates::class)
            ->set('kitType', 'small')
            ->call('loadStarterItems')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertGreaterThan(0, HsFirstAidItemTemplate::query()->where('kit_type', 'small')->count());

        Livewire::actingAs($officer)->test(FirstAidKitForm::class)
            ->set('kitType', 'small')->set('siteId', $site->id)
            ->call('save')->assertHasNoErrors();

        $kit = HsFirstAidKit::query()->firstOrFail();
        $this->assertSame(HsFirstAidItemTemplate::query()->where('kit_type', 'small')->count(), $kit->items()->count());

        $page = Livewire::actingAs($officer)->test(FirstAidKitShow::class, ['kit' => $kit]);
        $first = $kit->items()->first();
        $page->call('openPanel', 'check')
            ->set("itemEdits.{$first->id}.current_qty", 1)
            ->set('check.notes', 'Mostly empty.')
            ->call('recordCheck')
            ->assertHasNoErrors();
        $this->assertSame('fail', $kit->fresh()->last_check_result);

        $page->call('setMissing', true)->assertHasNoErrors();
        $this->assertSame('missing', $kit->fresh()->status);
    }

    public function test_the_overview_shows_the_equipment_row_and_each_tile_links_to_its_list(): void
    {
        $officer = $this->officer();
        $this->unit(['expiry_date' => today()->subDay()->toDateString()]);
        $this->kit([], [['Gauze', 2, 2, today()->subDay()->toDateString()]]);

        Livewire::actingAs($officer)->test(Home::class)
            ->assertSee('Extinguishers expired')
            ->assertSee('Kits with expired items')
            ->assertSee(route('health_safety.extinguishers.index', ['state' => 'expired']), false)
            ->assertSee(route('health_safety.extinguishers.index', ['expiring' => 30]), false)
            ->assertSee(route('health_safety.kits.index', ['state' => 'item_expired']), false);

        // Someone without view_equipment gets no equipment row.
        $role = \App\Models\Role::query()->create(['name' => 'dash_only_test', 'display_name' => 'Dash', 'is_system' => false]);
        $role->permissions()->attach(\App\Models\Permission::query()->where('name', 'health_safety.view_dashboard')->value('id'));
        \App\Models\ModuleAccess::query()->create(['role_id' => $role->id, 'module' => 'health_safety', 'can_access' => true]);
        $viewer = $this->userWithRoles('200070', ['employee']);
        $viewer->roles()->attach($role);

        Livewire::actingAs($viewer->fresh())->test(Home::class)->assertDontSee('Extinguishers expired');
    }

    public function test_the_sidebar_lists_the_equipment_screens_each_person_may_use(): void
    {
        $navigation = app(ErpNavigation::class);
        $labels = fn (User $user) => collect($navigation->build($user, 'health_safety')['sidebar'])->pluck('label')->all();

        $this->assertSame(['Overview', 'Report an incident', 'My reports', 'Incidents', 'Actions', 'Fire extinguishers', 'First aid kits', 'Expiry register', 'PPE stock', 'PPE issues', 'PPE gaps', 'My PPE', 'Kit templates', 'Import equipment', 'PPE types', 'PPE entitlements', 'PPE reorder levels', 'Sites'], $labels($this->officer()));
        $this->assertSame(['Overview', 'Report an incident', 'My reports', 'Incidents', 'Actions', 'Fire extinguishers', 'First aid kits', 'Expiry register', 'PPE stock', 'PPE issues', 'PPE gaps', 'My PPE'], $labels($this->districtManager('200003', $this->sowutuom)));
        $this->assertSame(['Report an incident', 'My reports', 'My PPE'], $labels($this->reporter()));
    }

    public function test_with_the_flag_off_the_equipment_routes_do_not_exist(): void
    {
        $names = ['health_safety.extinguishers.index', 'health_safety.extinguishers.create', 'health_safety.extinguishers.show', 'health_safety.extinguishers.edit',
            'health_safety.kits.index', 'health_safety.kits.create', 'health_safety.kits.show', 'health_safety.kits.edit', 'health_safety.kit-templates',
            'health_safety.equipment-import', 'health_safety.equipment-import.template', 'health_safety.equipment-files.show', 'health_safety.my-equipment'];

        foreach ($names as $name) {
            $this->assertTrue(Route::has($name), "sanity: {$name} is registered while the flag is on");
        }

        $_ENV['GWL_HEALTH_SAFETY_MODULE_ENABLED'] = $_SERVER['GWL_HEALTH_SAFETY_MODULE_ENABLED'] = 'false';
        putenv('GWL_HEALTH_SAFETY_MODULE_ENABLED=false');
        $this->refreshApplication();

        try {
            foreach ($names as $name) {
                $this->assertFalse(Route::has($name), "{$name} must not be registered when the flag is off");
            }

            $this->get('/health-safety/extinguishers')->assertNotFound();
        } finally {
            $_ENV['GWL_HEALTH_SAFETY_MODULE_ENABLED'] = $_SERVER['GWL_HEALTH_SAFETY_MODULE_ENABLED'] = 'true';
            putenv('GWL_HEALTH_SAFETY_MODULE_ENABLED=true');
        }
    }

    public function test_the_phase_two_settings_are_documented_and_have_the_stated_defaults(): void
    {
        $defaults = require base_path('config/gwl.php');
        $envExample = file_get_contents(base_path('.env.example'));

        foreach ([
            'GWL_HS_EXPIRY_WARNING_DAYS' => ['hs_expiry_warning_days', 60],
            'GWL_HS_EXPIRY_CRITICAL_DAYS' => ['hs_expiry_critical_days', 30],
            'GWL_HS_CHECK_INTERVAL_DAYS' => ['hs_check_interval_days', 30],
            'GWL_HS_EXTINGUISHER_SERVICE_MONTHS' => ['hs_extinguisher_service_months', 12],
            'GWL_HS_EXTINGUISHER_HYDRO_YEARS' => ['hs_extinguisher_hydro_years', 5],
            'GWL_HS_EQUIPMENT_ATTACHMENT_MAX_MB' => ['hs_equipment_attachment_max_mb', 5],
            'GWL_HS_REQUIRE_SECOND_APPROVER' => ['hs_require_second_approver', false],
        ] as $variable => [$key, $default]) {
            $this->assertStringContainsString($variable.'=', $envExample, "{$variable} is missing from .env.example");
            $this->assertArrayHasKey($key, $defaults);
            $this->assertSame($default, $defaults[$key], "gwl.{$key} default");
        }

        $this->assertStringContainsString('depends on the extinguisher type and must be confirmed', file_get_contents(base_path('config/gwl.php')));
    }
}
