<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AuditLog;
use App\Models\HsExtinguisherCheck;
use App\Models\HsExtinguisherService;
use App\Models\HsFireExtinguisher;
use App\Models\HsFirstAidItemTemplate;
use App\Models\HsFirstAidKit;
use App\Models\Vehicle;
use App\Services\HealthSafety\EquipmentAssetCodeGenerator;
use App\Services\HealthSafety\FireExtinguisherService;
use App\Services\HealthSafety\FirstAidKitService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EquipmentServicesTest extends HealthSafetyTestCase
{
    private function extinguishers(): FireExtinguisherService
    {
        return app(FireExtinguisherService::class);
    }

    private function kits(): FirstAidKitService
    {
        return app(FirstAidKitService::class);
    }

    private function vehicle(string $plate = 'GW 1234-24'): Vehicle
    {
        return Vehicle::query()->create(['type' => 'pickup', 'brand' => 'Toyota', 'model' => 'Hilux', 'number_plate' => $plate, 'status' => 'active']);
    }

    // ---------------------------------------------------------------- where it is

    public function test_exactly_one_of_site_or_vehicle_is_required(): void
    {
        $officer = $this->officer();
        $site = $this->site();
        $vehicle = $this->vehicle();

        foreach ([[], ['site_id' => $site->id, 'vehicle_id' => $vehicle->id]] as $place) {
            try {
                $this->extinguishers()->create($officer, ['extinguisher_type' => 'water', ...$place]);
                $this->fail('A unit must be at a site or in a vehicle, never neither or both.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('location', $exception->errors());
            }
        }

        $atSite = $this->extinguishers()->create($officer, ['extinguisher_type' => 'water', 'site_id' => $site->id]);
        $inVehicle = $this->extinguishers()->create($officer, ['extinguisher_type' => 'water', 'vehicle_id' => $vehicle->id]);

        $this->assertNull($atSite->vehicle_id);
        $this->assertNull($inVehicle->site_id);
        $this->assertSame($vehicle->id, $inVehicle->vehicle_id);

        // The same holds for kits.
        $this->expectException(ValidationException::class);
        $this->kits()->create($officer, ['kit_type' => 'small']);
    }

    public function test_region_and_district_are_copied_from_the_site(): void
    {
        $officer = $this->officer();
        $site = $this->site('Odorkor Office', $this->odorkor);

        $unit = $this->extinguishers()->create($officer, ['extinguisher_type' => 'water', 'site_id' => $site->id]);
        $kit = $this->kits()->create($officer, ['kit_type' => 'small', 'site_id' => $site->id]);

        foreach ([$unit, $kit] as $item) {
            $this->assertSame($this->accraWest->id, $item->region_id);
            $this->assertSame($this->odorkor->id, $item->district_id);
        }
    }

    public function test_a_vehicle_takes_its_region_and_district_from_the_form_and_the_district_must_be_in_the_region(): void
    {
        $officer = $this->officer();
        $vehicle = $this->vehicle();

        $unit = $this->extinguishers()->create($officer, ['extinguisher_type' => 'water', 'vehicle_id' => $vehicle->id, 'region_id' => $this->accraWest->id, 'district_id' => $this->odorkor->id]);
        $this->assertSame($this->accraWest->id, $unit->region_id);
        $this->assertSame($this->odorkor->id, $unit->district_id);

        // With no region given, the officer's own is used.
        $own = $this->extinguishers()->create($officer, ['extinguisher_type' => 'water', 'vehicle_id' => $vehicle->id]);
        $this->assertSame($this->accraWest->id, $own->region_id);
        $this->assertNull($own->district_id);

        try {
            $this->extinguishers()->create($officer, ['extinguisher_type' => 'water', 'vehicle_id' => $vehicle->id, 'region_id' => $this->accraWest->id, 'district_id' => $this->kumasi->id]);
            $this->fail('A district from another region must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('district_id', $exception->errors());
        }
    }

    public function test_an_officer_cannot_place_equipment_in_another_region(): void
    {
        $officer = $this->officer();
        $kumasiSite = $this->site('Kumasi Office', $this->kumasi);

        $this->expectException(HttpException::class);

        $this->extinguishers()->create($officer, ['extinguisher_type' => 'water', 'site_id' => $kumasiSite->id]);
    }

    public function test_a_deactivated_site_cannot_take_new_equipment(): void
    {
        $officer = $this->officer();
        $site = $this->site();
        $site->update(['is_active' => false]);

        $this->expectException(ValidationException::class);

        $this->extinguishers()->create($officer, ['extinguisher_type' => 'water', 'site_id' => $site->id]);
    }

    public function test_changing_a_sites_district_updates_its_equipment(): void
    {
        $officer = $this->officer();
        $site = $this->site('Moving Office', $this->sowutuom);
        $unit = $this->extinguishers()->create($officer, ['extinguisher_type' => 'water', 'site_id' => $site->id]);
        $kit = $this->kits()->create($officer, ['kit_type' => 'small', 'site_id' => $site->id]);
        $other = $this->unit();

        \Livewire\Livewire::actingAs($officer)->test(\App\Livewire\HealthSafety\Sites::class)
            ->call('edit', $site->id)
            ->set('districtId', $this->odorkor->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($this->odorkor->id, $unit->fresh()->district_id);
        $this->assertSame($this->odorkor->id, $kit->fresh()->district_id);
        $this->assertSame($this->sowutuom->id, $other->fresh()->district_id, 'equipment at other sites is untouched');
    }

    // ---------------------------------------------------------------- asset codes

    public function test_asset_codes_are_generated_unique_and_typed_ones_are_kept(): void
    {
        $officer = $this->officer();
        $site = $this->site();

        $first = $this->extinguishers()->create($officer, ['extinguisher_type' => 'water', 'site_id' => $site->id]);
        $second = $this->extinguishers()->create($officer, ['extinguisher_type' => 'water', 'site_id' => $site->id]);
        $kit = $this->kits()->create($officer, ['kit_type' => 'small', 'site_id' => $site->id]);

        $this->assertMatchesRegularExpression('/^FE-[A-Z0-9]+-\d{4}$/', $first->asset_code);
        $this->assertStringEndsWith('-0001', $first->asset_code);
        $this->assertStringEndsWith('-0002', $second->asset_code);
        $this->assertMatchesRegularExpression('/^FAK-[A-Z0-9]+-0001$/', $kit->asset_code, 'kits count separately');

        $typed = $this->extinguishers()->create($officer, ['extinguisher_type' => 'water', 'site_id' => $site->id, 'asset_code' => 'GWL/FE/0042']);
        $this->assertSame('GWL/FE/0042', $typed->asset_code);

        try {
            $this->extinguishers()->create($officer, ['extinguisher_type' => 'water', 'site_id' => $site->id, 'asset_code' => 'GWL/FE/0042']);
            $this->fail('A typed code that already exists must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('asset_code', $exception->errors());
        }

        // An earlier code with a higher number makes the generator skip past it.
        HsFireExtinguisher::query()->whereKey($first->id)->update(['asset_code' => substr($first->asset_code, 0, -4).'0099']);
        $this->assertStringEndsWith('-0100', $this->extinguishers()->create($officer, ['extinguisher_type' => 'water', 'site_id' => $site->id])->asset_code);
    }

    public function test_a_generated_code_that_collides_is_asked_for_again(): void
    {
        $officer = $this->officer();
        $site = $this->site();
        $first = $this->extinguishers()->create($officer, ['extinguisher_type' => 'water', 'site_id' => $site->id]);

        $generator = new class($first->asset_code) extends EquipmentAssetCodeGenerator
        {
            public int $calls = 0;

            public function __construct(private string $taken) {}

            public function nextExtinguisher(\App\Models\Region $region): string
            {
                $this->calls++;

                return $this->calls === 1 ? $this->taken : parent::nextExtinguisher($region);
            }
        };

        $service = new FireExtinguisherService(app(\App\Services\HealthSafety\EquipmentScope::class), app(\App\Services\HealthSafety\EquipmentLocation::class), $generator);
        $second = $service->create($officer, ['extinguisher_type' => 'water', 'site_id' => $site->id]);

        $this->assertNotSame($first->asset_code, $second->asset_code);
        $this->assertSame(2, $generator->calls);
    }

    // ---------------------------------------------------------------- recording a service

    public function test_recording_a_service_updates_the_dates_and_prefills_from_config(): void
    {
        config(['gwl.hs_extinguisher_service_months' => 12, 'gwl.hs_extinguisher_hydro_years' => 5]);
        $officer = $this->officer();
        $unit = $this->extinguishers()->create($officer, $this->extinguisherData(['expiry_date' => today()->addMonth()->toDateString()]));

        $servicedOn = today()->subDays(3);
        $this->extinguishers()->recordService($unit, $officer, ['serviced_on' => $servicedOn->toDateString(), 'service_type' => 'refill', 'vendor' => 'FireSafe Ltd']);

        $unit = $unit->fresh();
        $this->assertSame($servicedOn->toDateString(), $unit->last_serviced_on->toDateString());
        $this->assertSame($servicedOn->copy()->addMonths(12)->toDateString(), $unit->next_service_due->toDateString(), 'next service pre-filled from hs_extinguisher_service_months');
        $this->assertSame(today()->addMonth()->toDateString(), $unit->expiry_date->toDateString(), 'the expiry is left alone when no new one is given');
        $this->assertNull($unit->last_hydro_test_on);

        // A hydrostatic test sets the test dates too, and a new expiry replaces the old.
        $newExpiry = today()->addYears(3)->toDateString();
        $this->extinguishers()->recordService($unit, $officer, ['serviced_on' => today()->toDateString(), 'service_type' => 'hydro_test', 'new_expiry_date' => $newExpiry]);

        $unit = $unit->fresh();
        $this->assertSame(today()->toDateString(), $unit->last_hydro_test_on->toDateString());
        $this->assertSame(today()->addYears(5)->toDateString(), $unit->next_hydro_test_due->toDateString(), 'next test pre-filled from hs_extinguisher_hydro_years');
        $this->assertSame($newExpiry, $unit->expiry_date->toDateString());
        $this->assertSame(today()->addMonths(12)->toDateString(), $unit->next_service_due->toDateString());

        $this->assertSame(2, $unit->services()->count());
        $this->assertTrue(AuditLog::query()->where('action', 'health_safety.extinguisher_serviced')->where('target_id', $unit->id)->exists());
    }

    public function test_dates_given_with_the_service_override_the_prefill_and_the_config_is_honoured(): void
    {
        config(['gwl.hs_extinguisher_service_months' => 6, 'gwl.hs_extinguisher_hydro_years' => 10]);
        $officer = $this->officer();
        $unit = $this->extinguishers()->create($officer, $this->extinguisherData());

        $this->extinguishers()->recordService($unit, $officer, ['serviced_on' => today()->toDateString(), 'service_type' => 'inspection']);
        $this->assertSame(today()->addMonths(6)->toDateString(), $unit->fresh()->next_service_due->toDateString());

        $this->extinguishers()->recordService($unit, $officer, [
            'serviced_on' => today()->toDateString(), 'service_type' => 'hydro_test',
            'next_service_due' => today()->addMonths(3)->toDateString(), 'next_hydro_test_due' => today()->addYears(2)->toDateString(),
        ]);

        $unit = $unit->fresh();
        $this->assertSame(today()->addMonths(3)->toDateString(), $unit->next_service_due->toDateString());
        $this->assertSame(today()->addYears(2)->toDateString(), $unit->next_hydro_test_due->toDateString());
    }

    public function test_recording_a_service_returns_an_out_for_service_unit_to_service(): void
    {
        $officer = $this->officer();
        $unit = $this->extinguishers()->create($officer, $this->extinguisherData());

        $this->extinguishers()->changeStatus($unit, $officer, 'out_for_service');
        $this->assertSame('out_for_service', $unit->fresh()->status);

        $this->extinguishers()->recordService($unit, $officer, ['serviced_on' => today()->toDateString(), 'service_type' => 'recharge']);

        $this->assertSame('in_service', $unit->fresh()->status);
    }

    public function test_a_service_dated_before_the_latest_is_kept_in_the_history_without_moving_the_dates(): void
    {
        $officer = $this->officer();
        $unit = $this->extinguishers()->create($officer, $this->extinguisherData());
        $this->extinguishers()->recordService($unit, $officer, ['serviced_on' => today()->toDateString(), 'service_type' => 'inspection']);
        $due = $unit->fresh()->next_service_due->toDateString();

        $this->extinguishers()->recordService($unit, $officer, ['serviced_on' => today()->subYear()->toDateString(), 'service_type' => 'inspection']);

        $unit = $unit->fresh();
        $this->assertSame(2, $unit->services()->count());
        $this->assertSame(today()->toDateString(), $unit->last_serviced_on->toDateString());
        $this->assertSame($due, $unit->next_service_due->toDateString());
    }

    public function test_a_service_cannot_be_dated_in_the_future_or_be_of_an_unknown_kind(): void
    {
        $officer = $this->officer();
        $unit = $this->extinguishers()->create($officer, $this->extinguisherData());

        foreach ([
            ['serviced_on' => today()->addDay()->toDateString(), 'service_type' => 'inspection'],
            ['serviced_on' => today()->toDateString(), 'service_type' => 'replacement'],
            ['serviced_on' => today()->toDateString(), 'service_type' => 'inspection', 'next_service_due' => today()->subDay()->toDateString()],
        ] as $data) {
            try {
                $this->extinguishers()->recordService($unit, $officer, $data);
                $this->fail('This service should have been refused: '.json_encode($data));
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }

        $this->assertSame(0, $unit->services()->count());
    }

    public function test_a_certificate_is_kept_on_the_private_disk_and_only_a_pdf_jpg_or_png_is_accepted(): void
    {
        $officer = $this->officer();
        $unit = $this->extinguishers()->create($officer, $this->extinguisherData());

        $service = $this->extinguishers()->recordService($unit, $officer, ['serviced_on' => today()->toDateString(), 'service_type' => 'inspection'], UploadedFile::fake()->create('cert.pdf', 50, 'application/pdf'));

        $this->assertStringStartsWith('health_safety/equipment/'.$unit->id.'/', $service->certificate_path);
        Storage::disk('local')->assertExists($service->certificate_path);
        $this->assertSame('cert.pdf', $service->certificate_name);

        try {
            $this->extinguishers()->recordService($unit, $officer, ['serviced_on' => today()->toDateString(), 'service_type' => 'inspection'], UploadedFile::fake()->create('macro.exe', 10));
            $this->fail('An executable must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('certificate', $exception->errors());
        }

        $this->assertSame(1, $unit->services()->count(), 'a refused certificate leaves no service behind');
        $this->assertCount(1, Storage::disk('local')->allFiles('health_safety/equipment'));
    }

    // ---------------------------------------------------------------- recording a check

    public function test_recording_a_check_updates_the_last_check_date_and_result(): void
    {
        $officer = $this->officer();
        $unit = $this->extinguishers()->create($officer, $this->extinguisherData());

        $check = $this->extinguishers()->recordCheck($unit, $officer, [
            'checked_on' => today()->toDateString(), 'in_place' => true, 'accessible' => true, 'seal_intact' => true,
            'pressure_ok' => true, 'no_damage' => true, 'signage_ok' => true,
        ]);

        $this->assertSame(HsExtinguisherCheck::RESULT_PASS, $check->result);
        $unit = $unit->fresh();
        $this->assertSame(today()->toDateString(), $unit->last_checked_on->toDateString());
        $this->assertSame('pass', $unit->last_check_result);
        $this->assertSame('ok', $unit->state());

        $this->extinguishers()->recordCheck($unit, $officer, [
            'checked_on' => today()->toDateString(), 'in_place' => true, 'accessible' => true, 'seal_intact' => false,
            'pressure_ok' => true, 'no_damage' => true, 'signage_ok' => true, 'notes' => 'Seal is broken.',
        ]);

        $unit = $unit->fresh();
        $this->assertSame('fail', $unit->last_check_result);
        $this->assertSame('check_failed', $unit->state());
        $this->assertSame(['Seal / pin intact'], $unit->checks()->latest('id')->first()->failedPoints());
        $this->assertTrue(AuditLog::query()->where('action', 'health_safety.extinguisher_checked')->exists());
    }

    public function test_a_failed_check_needs_a_note_and_a_check_cannot_be_dated_in_the_future(): void
    {
        $officer = $this->officer();
        $unit = $this->extinguishers()->create($officer, $this->extinguisherData());
        $points = ['in_place' => true, 'accessible' => true, 'seal_intact' => true, 'pressure_ok' => false, 'no_damage' => true, 'signage_ok' => true];

        try {
            $this->extinguishers()->recordCheck($unit, $officer, ['checked_on' => today()->toDateString(), ...$points]);
            $this->fail('A failed check needs a note.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('notes', $exception->errors());
        }

        try {
            $this->extinguishers()->recordCheck($unit, $officer, ['checked_on' => today()->addDay()->toDateString(), ...$points, 'pressure_ok' => true]);
            $this->fail('A check cannot be in the future.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('checked_on', $exception->errors());
        }

        $this->assertSame(0, $unit->checks()->count());
    }

    public function test_checks_and_services_are_immutable(): void
    {
        $service = app(FireExtinguisherService::class);

        foreach (['updateCheck', 'deleteCheck', 'updateService', 'deleteService', 'editCheck', 'editService'] as $method) {
            $this->assertFalse(method_exists($service, $method), "FireExtinguisherService has no {$method}");
        }

        foreach (['updateCheck', 'deleteCheck', 'editCheck'] as $method) {
            $this->assertFalse(method_exists($this->kits(), $method), "FirstAidKitService has no {$method}");
        }

        $routes = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with($route->getName() ?? '', 'health_safety.'));
        $this->assertSame([], $routes->filter(fn ($route) => array_intersect($route->methods(), ['PUT', 'PATCH', 'DELETE']))->map->getName()->values()->all(), 'no route edits or deletes a record');
    }

    // ---------------------------------------------------------------- status and decommissioning

    public function test_status_changes_are_audited_and_decommissioning_needs_a_reason(): void
    {
        $officer = $this->officer();
        $unit = $this->extinguishers()->create($officer, $this->extinguisherData());

        $this->extinguishers()->changeStatus($unit, $officer, 'discharged');
        $this->assertSame('discharged', $unit->fresh()->status);
        $this->assertTrue(AuditLog::query()->where('action', 'health_safety.extinguisher_status_changed')->exists());

        try {
            $this->extinguishers()->changeStatus($unit, $officer, 'decommissioned');
            $this->fail('Decommissioning is its own action, with a reason.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        try {
            $this->extinguishers()->decommission($unit, $officer, '   ');
            $this->fail('A reason is required.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }

        $this->assertNotSame('decommissioned', $unit->fresh()->status);

        $done = $this->extinguishers()->decommission($unit, $officer, 'Corroded beyond repair.');
        $this->assertSame('decommissioned', $done->status);
        $this->assertSame(today()->toDateString(), $done->decommissioned_on->toDateString());
        $this->assertSame('Corroded beyond repair.', $done->decommission_reason);
        $this->assertTrue(AuditLog::query()->where('action', 'health_safety.extinguisher_decommissioned')->exists());

        // A decommissioned unit takes no further change.
        foreach ([
            fn () => $this->extinguishers()->changeStatus($done, $officer, 'in_service'),
            fn () => $this->extinguishers()->recordService($done, $officer, ['serviced_on' => today()->toDateString(), 'service_type' => 'inspection']),
            fn () => $this->extinguishers()->recordCheck($done, $officer, ['checked_on' => today()->toDateString(), 'in_place' => true, 'accessible' => true, 'seal_intact' => true, 'pressure_ok' => true, 'no_damage' => true, 'signage_ok' => true]),
            fn () => $this->extinguishers()->update($done, $officer, $this->extinguisherData()),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A decommissioned extinguisher must stay as it is.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('status', $exception->errors());
            }
        }
    }

    // ---------------------------------------------------------------- kits and templates

    public function test_a_kit_is_filled_by_copying_the_templates_and_a_later_edit_does_not_touch_it(): void
    {
        $officer = $this->officer();
        $this->kits()->saveTemplates($this->hsManager(), 'medium', [
            ['item_name' => 'Gauze', 'required_qty' => 6, 'has_expiry' => true],
            ['item_name' => 'Scissors', 'required_qty' => 1, 'has_expiry' => false],
        ]);

        $kit = $this->kits()->create($officer, ['kit_type' => 'medium', 'site_id' => $this->site()->id]);

        $this->assertSame(['Gauze', 'Scissors'], $kit->items->pluck('item_name')->all());
        $this->assertSame([6, 1], $kit->items->pluck('required_qty')->all());
        $this->assertSame([0, 0], $kit->items->pluck('current_qty')->all(), 'a new kit holds nothing until it is checked');
        $this->assertTrue($kit->items->first()->has_expiry);

        // The template changes; the kit does not.
        $this->kits()->saveTemplates($this->hsManager(), 'medium', [
            ['item_name' => 'Gauze', 'required_qty' => 20, 'has_expiry' => true],
            ['item_name' => 'Burn gel', 'required_qty' => 2, 'has_expiry' => true],
        ]);

        $this->assertSame(['Gauze', 'Scissors'], $kit->fresh()->items->pluck('item_name')->all());
        $this->assertSame([6, 1], $kit->fresh()->items->pluck('required_qty')->all());

        // A kit of another type gets none of it, and a new medium kit gets the new template.
        $this->assertCount(0, $this->kits()->create($officer, ['kit_type' => 'large', 'site_id' => $this->site('Another')->id])->items);
        $this->assertSame(['Gauze', 'Burn gel'], $this->kits()->create($officer, ['kit_type' => 'medium', 'site_id' => $this->site('Third')->id])->items->pluck('item_name')->all());
    }

    public function test_no_template_rows_exist_after_migrate_and_seed(): void
    {
        $this->assertSame(0, HsFirstAidItemTemplate::query()->count());
        $this->artisan('db:seed', ['--class' => \Database\Seeders\HealthSafetyRolePermissionSeeder::class]);
        $this->assertSame(0, HsFirstAidItemTemplate::query()->count());

        // A kit made with no templates is simply empty.
        $kit = $this->kits()->create($this->officer(), ['kit_type' => 'small', 'site_id' => $this->site()->id]);
        $this->assertCount(0, $kit->items);
    }

    public function test_templates_are_unique_per_type_and_name_and_need_manage_master_data(): void
    {
        try {
            $this->kits()->saveTemplates($this->hsManager(), 'small', [
                ['item_name' => 'Gauze', 'required_qty' => 2], ['item_name' => ' gauze ', 'required_qty' => 3],
            ]);
            $this->fail('The same item twice is refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }

        $this->assertSame(0, HsFirstAidItemTemplate::query()->count());

        $this->assertSame(1, $this->kits()->saveTemplates($this->hsManager(), 'small', [['item_name' => 'Gauze', 'required_qty' => 2], ['item_name' => '', 'required_qty' => 1]]), 'blank rows are ignored');
        $this->assertTrue(AuditLog::query()->where('action', 'health_safety.kit_template_saved')->exists());

        $this->expectException(HttpException::class);
        $this->kits()->saveTemplates($this->districtManager('200003', $this->sowutuom), 'small', [['item_name' => 'Gauze', 'required_qty' => 2]]);
    }

    public function test_recording_a_kit_check_updates_the_items_and_the_last_check(): void
    {
        $officer = $this->officer();
        $kit = $this->kit(['last_checked_on' => today()->subDays(40)->toDateString()], [['Gauze', 6, 2, today()->addDays(10)->toDateString()], ['Scissors', 1, 0, null]]);
        $gauze = $kit->items->firstWhere('item_name', 'Gauze');
        $scissors = $kit->items->firstWhere('item_name', 'Scissors');

        $this->assertSame('item_expiring', $kit->fresh()->state());

        $check = $this->kits()->recordCheck($kit, $officer, ['checked_on' => today()->toDateString(), 'restocked' => true], [
            $gauze->id => ['current_qty' => 6, 'expiry_date' => today()->addYear()->toDateString()],
            $scissors->id => ['current_qty' => 1],
        ]);

        $this->assertSame('pass', $check->result);
        $this->assertTrue($check->restocked);
        $kit = $kit->fresh();
        $this->assertSame(today()->toDateString(), $kit->last_checked_on->toDateString());
        $this->assertSame('pass', $kit->last_check_result);
        $this->assertSame(6, $gauze->fresh()->current_qty);
        $this->assertSame(today()->addYear()->toDateString(), $gauze->fresh()->expiry_date->toDateString());
        $this->assertSame('ok', $kit->state());
        $this->assertTrue(AuditLog::query()->where('action', 'health_safety.kit_checked')->exists());
    }

    public function test_a_kit_check_that_finds_a_problem_fails_and_needs_a_note(): void
    {
        $officer = $this->officer();
        $kit = $this->kit([], [['Gauze', 6, 6, null]]);
        $gauze = $kit->items->first();

        try {
            $this->kits()->recordCheck($kit, $officer, ['checked_on' => today()->toDateString()], [$gauze->id => ['current_qty' => 2]]);
            $this->fail('A short kit fails the check and the officer must say why.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('notes', $exception->errors());
        }

        $this->assertSame(6, $gauze->fresh()->current_qty, 'a refused check changes nothing');
        $this->assertSame(0, $kit->checks()->count());

        $check = $this->kits()->recordCheck($kit, $officer, ['checked_on' => today()->toDateString(), 'notes' => 'Used on an injury, needs restocking.'], [$gauze->id => ['current_qty' => 2]]);
        $this->assertSame('fail', $check->result);
        $this->assertSame('incomplete', $kit->fresh()->state());
    }

    public function test_an_item_id_from_another_kit_is_ignored_in_a_check(): void
    {
        $officer = $this->officer();
        $kit = $this->kit([], [['Gauze', 2, 2, null]]);
        $other = $this->kit([], [['Gauze', 5, 5, null]]);
        $foreign = $other->items->first();

        $this->kits()->recordCheck($kit, $officer, ['checked_on' => today()->toDateString()], [$foreign->id => ['current_qty' => 0]]);

        $this->assertSame(5, $foreign->fresh()->current_qty);
    }

    public function test_a_kit_can_be_marked_missing_found_and_decommissioned(): void
    {
        $officer = $this->officer();
        $kit = $this->kit([], [['Gauze', 2, 2, null]]);

        $this->assertSame('missing', $this->kits()->setMissing($kit, $officer, true)->state());
        $this->assertSame('ok', $this->kits()->setMissing($kit, $officer, false)->state());

        try {
            $this->kits()->decommission($kit, $officer, '');
            $this->fail('A reason is required.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }

        $done = $this->kits()->decommission($kit, $officer, 'Replaced by a larger kit.');
        $this->assertSame('decommissioned', $done->status);
        $this->assertNotNull($done->decommissioned_on);
        $this->assertTrue(AuditLog::query()->where('action', 'health_safety.kit_decommissioned')->exists());

        $this->expectException(ValidationException::class);
        $this->kits()->setMissing($done, $officer, true);
    }

    public function test_the_contents_list_can_be_edited_without_touching_what_the_kit_holds(): void
    {
        $officer = $this->officer();
        $kit = $this->kit([], [['Gauze', 2, 2, null], ['Tape', 1, 1, null]]);
        [$gauze, $tape] = [$kit->items[0], $kit->items[1]];

        $this->kits()->saveItems($kit, $officer, [
            ['id' => $gauze->id, 'item_name' => 'Sterile gauze', 'required_qty' => 8, 'has_expiry' => true],
            ['item_name' => 'Scissors', 'required_qty' => 1],
        ]);

        $kit = $kit->fresh()->load('items');
        $this->assertSame(['Sterile gauze', 'Scissors'], $kit->items->pluck('item_name')->all());
        $this->assertSame(2, $kit->items->first()->current_qty, 'quantities held are recorded at a check, not here');
        $this->assertSame(8, $kit->items->first()->required_qty);
        $this->assertNull($tape->fresh(), 'a removed item is gone');
    }

    public function test_every_mutation_is_audited(): void
    {
        $officer = $this->officer();
        $unit = $this->extinguishers()->create($officer, $this->extinguisherData());
        $this->extinguishers()->update($unit, $officer, $this->extinguisherData(['site_id' => $unit->site_id, 'notes' => 'Edited.']));
        $kit = $this->kits()->create($officer, ['kit_type' => 'small', 'site_id' => $unit->site_id]);

        foreach (['health_safety.extinguisher_saved', 'health_safety.kit_saved'] as $action) {
            $this->assertTrue(AuditLog::query()->where('action', $action)->exists(), $action);
        }

        $this->assertSame(2, AuditLog::query()->where('action', 'health_safety.extinguisher_saved')->count(), 'create and update');
        $this->assertNotNull($kit);
    }

    // ---------------------------------------------------------------- the schema

    public function test_the_schema_has_the_columns_indexes_and_uniques_the_design_calls_for(): void
    {
        $indexed = function (string $table): array {
            return collect(\Illuminate\Support\Facades\Schema::getIndexes($table))->map(fn ($index) => implode(',', $index['columns']).($index['unique'] ? ' (unique)' : ''))->all();
        };

        foreach (['hs_fire_extinguishers', 'hs_first_aid_kits'] as $table) {
            $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumns($table, ['region_id', 'district_id', 'site_id', 'vehicle_id', 'last_check_result', 'decommissioned_on', 'decommission_reason', 'last_checked_on', 'responsible_employee_id']), "{$table} columns");
            $this->assertContains('asset_code (unique)', $indexed($table), "{$table} asset_code unique");
            $this->assertContains('region_id,status', $indexed($table));
            $this->assertContains('site_id', $indexed($table));
            $this->assertContains('vehicle_id', $indexed($table));
        }

        $this->assertContains('expiry_date', $indexed('hs_fire_extinguishers'));
        $this->assertContains('next_service_due', $indexed('hs_fire_extinguishers'));
        $this->assertContains('kit_id,expiry_date', $indexed('hs_first_aid_kit_items'));
        $this->assertContains('kit_type,item_name (unique)', $indexed('hs_first_aid_item_templates'));

        $vehicleKey = collect(\Illuminate\Support\Facades\Schema::getForeignKeys('hs_fire_extinguishers'))->firstWhere('columns', ['vehicle_id']);
        $this->assertSame('vehicles', $vehicleKey['foreign_table']);
        $this->assertSame('set null', $vehicleKey['on_delete']);

        $regionKey = collect(\Illuminate\Support\Facades\Schema::getForeignKeys('hs_fire_extinguishers'))->firstWhere('columns', ['region_id']);
        $this->assertSame('restrict', $regionKey['on_delete']);

        $checkKey = collect(\Illuminate\Support\Facades\Schema::getForeignKeys('hs_extinguisher_checks'))->firstWhere('columns', ['extinguisher_id']);
        $this->assertSame('cascade', $checkKey['on_delete']);
    }

    public function test_a_soft_deleted_vehicle_keeps_its_equipment_and_the_register_still_names_it(): void
    {
        $vehicle = $this->vehicle('GW 9-24');
        $unit = $this->extinguishers()->create($this->officer(), ['extinguisher_type' => 'water', 'vehicle_id' => $vehicle->id]);

        $vehicle->delete();

        $unit = $unit->fresh();
        $this->assertSame($vehicle->id, $unit->vehicle_id, 'a soft delete leaves the link');
        $this->assertSame('GW 9-24', $unit->vehicle->number_plate);
        $this->assertStringContainsString('GW 9-24', $unit->locationLabel());
    }
}
