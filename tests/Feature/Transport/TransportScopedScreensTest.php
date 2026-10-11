<?php

namespace Tests\Feature\Transport;

use App\Exports\Transport\VehicleExpensesExport;
use App\Livewire\Transport\Dashboard;
use App\Livewire\Transport\Expenses;
use App\Livewire\Transport\Issues;
use App\Livewire\Transport\Maintenance;
use App\Livewire\Transport\Mileage;
use App\Livewire\Transport\Reports;
use App\Livewire\Transport\Vehicles;
use App\Models\MileageLog;
use App\Models\Vehicle;
use App\Models\VehicleExpense;
use App\Models\VehicleIssue;
use App\Services\ReportsService;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

/**
 * The Transport screens, exports and driver API as seen by users in different places: another region's vehicles and
 * everything hanging off them are not listed, not exported, and answer 404 when asked for by id.
 */
class TransportScopedScreensTest extends TransportTestCase
{
    // ---------------------------------------------------------------- vehicles screen

    public function test_the_vehicle_list_shows_only_the_users_own_location(): void
    {
        $this->vehicleAt($this->sowutuom, 'ACC-1');
        $this->vehicleAt($this->headOffice, 'HO-1');
        $this->vehicleAt($this->kumasi, 'KUM-1');
        $this->unplacedVehicle('NOPL-1');

        Livewire::actingAs($this->manager('M1', $this->sowutuom))->test(Vehicles::class)
            ->assertSee('ACC-1')->assertDontSee('HO-1')->assertDontSee('KUM-1')->assertDontSee('NOPL-1');

        Livewire::actingAs($this->manager('M3', $this->headOffice))->test(Vehicles::class)
            ->assertSee('HO-1')->assertDontSee('ACC-1')->assertDontSee('KUM-1')->assertDontSee('NOPL-1');

        Livewire::actingAs($this->superAdmin())->test(Vehicles::class)
            ->assertSee('ACC-1')->assertSee('HO-1')->assertSee('KUM-1')->assertSee('NOPL-1');
    }

    public function test_another_regions_vehicle_cannot_be_opened_edited_or_saved_over(): void
    {
        $kum = $this->vehicleAt($this->kumasi, 'KUM-1');
        $manager = $this->manager('M1', $this->sowutuom);

        Livewire::actingAs($manager)->test(Vehicles::class)->call('openEdit', $kum->id)->assertNotFound();
        Livewire::actingAs($manager)->test(Vehicles::class)->call('viewVehicle', $kum->id)->assertNotFound();

        // An edit started on an allowed vehicle cannot be redirected to a foreign one by changing the id.
        Livewire::actingAs($manager)->test(Vehicles::class)
            ->set('editingVehicleId', $kum->id)
            ->set('form.brand', 'Hijacked')->set('form.model', 'X')->set('form.number_plate', 'KUM-1')
            ->call('save')
            ->assertNotFound();

        $this->assertNotSame('Hijacked', $kum->fresh()->brand);
    }

    public function test_a_new_vehicle_form_is_prefilled_with_the_actors_place_and_the_pickers_are_limited_to_it(): void
    {
        $this->person('LOCAL1', $this->odorkor);
        $this->person('OTHER1', $this->kumasi);
        $this->person('HOFF1', $this->headOffice);
        $this->driver('DRVL1', $this->odorkor);
        $this->driver('DRVK1', $this->kumasi);
        $manager = $this->manager('M1', $this->sowutuom);

        Livewire::actingAs($manager)->test(Vehicles::class)
            ->call('openCreate')
            ->assertSet('form.region_id', $this->accraWest->id)
            ->assertSet('form.district_id', $this->sowutuom->id)
            ->assertSee('Accra West / Sowutuom')
            ->set('form.is_pool_car', false)
            ->set('form.driver_type', Vehicle::DRIVER_ASSIGNED)
            ->assertSee('Staff LOCAL1')->assertSee('Staff DRVL1')
            ->assertDontSee('Staff OTHER1')->assertDontSee('Staff HOFF1')->assertDontSee('Staff DRVK1')
            // Changing the posted location does not change who is offered.
            ->set('form.region_id', $this->ashanti->id)
            ->assertDontSee('Staff OTHER1')->assertDontSee('Staff DRVK1');
    }

    public function test_saving_through_the_form_places_the_vehicle_where_the_actor_works_whatever_was_posted(): void
    {
        $manager = $this->manager('M1', $this->sowutuom);

        Livewire::actingAs($manager)->test(Vehicles::class)
            ->call('openCreate')
            ->set('form.region_id', $this->ashanti->id)
            ->set('form.district_id', $this->kumasi->id)
            ->set('form.brand', 'Toyota')->set('form.model', 'Hilux')->set('form.number_plate', 'LW-1')
            ->call('save')
            ->assertHasNoErrors();

        $vehicle = Vehicle::query()->where('number_plate', 'LW-1')->firstOrFail();
        $this->assertSame([$this->accraWest->id, $this->sowutuom->id], [$vehicle->region_id, $vehicle->district_id]);
    }

    public function test_only_super_admin_gets_location_pickers_and_must_choose_one(): void
    {
        $super = $this->superAdmin();

        Livewire::actingAs($super)->test(Vehicles::class)
            ->call('openCreate')
            ->assertSee('Select region')
            ->set('form.brand', 'Toyota')->set('form.model', 'Hilux')->set('form.number_plate', 'SA-LW-1')
            ->call('save')
            ->assertHasErrors(['form.region_id'])
            ->set('form.region_id', $this->ashanti->id)
            ->set('form.district_id', $this->kumasi->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($this->kumasi->id, Vehicle::query()->where('number_plate', 'SA-LW-1')->value('district_id'));

        Livewire::actingAs($this->manager('M1', $this->sowutuom))->test(Vehicles::class)
            ->call('openCreate')->assertDontSee('Select region');
    }

    public function test_the_import_cannot_touch_or_reveal_another_regions_vehicle(): void
    {
        $kum = $this->vehicleAt($this->kumasi, 'KUM-1', ['brand' => 'Original']);
        $manager = $this->manager('M1', $this->sowutuom);
        $csv = "number_plate,type,brand,model\nIMP-1,sedan,Toyota,Corolla\nKUM-1,sedan,Hijacked,Corolla\n";

        $page = Livewire::actingAs($manager)->test(Vehicles::class)
            ->set('importFile', UploadedFile::fake()->createWithContent('vehicles.csv', $csv))
            ->call('importVehicles');

        $this->assertSame(['created' => 1, 'updated' => 0, 'failed' => 1], $page->get('importSummary'));
        $this->assertSame('Original', $kum->fresh()->brand);
        $this->assertSame($this->sowutuom->id, Vehicle::query()->where('number_plate', 'IMP-1')->value('district_id'));
    }

    // ---------------------------------------------------------------- records screens

    public function test_dashboard_expenses_and_maintenance_lists_and_pickers_exclude_other_places(): void
    {
        $acc = $this->vehicleAt($this->sowutuom, 'ACC-1');
        $ho = $this->vehicleAt($this->headOffice, 'HO-1');
        $kum = $this->vehicleAt($this->kumasi, 'KUM-1');
        $this->expense($acc, ['description' => 'Fuel ACC']);
        $this->expense($ho, ['description' => 'Fuel HO']);
        $this->expense($kum, ['description' => 'Fuel KUM']);
        $manager = $this->manager('M1', $this->sowutuom);

        Livewire::actingAs($manager)->test(Dashboard::class)
            ->assertViewHas('stats', fn (array $stats) => $stats['total'] === 1);

        Livewire::actingAs($this->superAdmin())->test(Dashboard::class)
            ->assertViewHas('stats', fn (array $stats) => $stats['total'] === 3);

        Livewire::actingAs($manager)->test(Expenses::class)
            ->assertSee('Fuel ACC')->assertDontSee('Fuel HO')->assertDontSee('Fuel KUM')
            ->assertSee('ACC-1')->assertDontSee('HO-1')->assertDontSee('KUM-1');

        Livewire::actingAs($manager)->test(Maintenance::class)
            ->assertSee('ACC-1')->assertDontSee('HO-1')->assertDontSee('KUM-1');
    }

    public function test_recording_against_another_regions_vehicle_answers_not_found(): void
    {
        $kum = $this->vehicleAt($this->kumasi, 'KUM-1');
        $manager = $this->manager('M1', $this->sowutuom);

        Livewire::actingAs($manager)->test(Expenses::class)
            ->set('form', ['vehicle_id' => $kum->id, 'expense_type' => 'fuel', 'amount' => '50', 'currency' => 'GHS', 'description' => '', 'expense_date' => today()->toDateString()])
            ->call('save')
            ->assertNotFound();

        Livewire::actingAs($manager)->test(Maintenance::class)
            ->set('form', ['vehicle_id' => $kum->id, 'maintenance_type' => 'repair', 'description' => '', 'performed_by' => '', 'mileage_at_service' => '', 'cost' => '10', 'service_date' => today()->toDateString(), 'next_service_date' => '', 'next_service_mileage' => ''])
            ->call('save')
            ->assertNotFound();

        Livewire::actingAs($manager)->test(Maintenance::class)
            ->set('renewal', ['vehicle_id' => $kum->id, 'type' => 'insurance', 'expiry_date' => today()->addYear()->toDateString(), 'amount' => ''])
            ->call('renewDocument')
            ->assertNotFound();

        $this->assertSame(0, VehicleExpense::query()->where('vehicle_id', $kum->id)->count());
    }

    public function test_issues_are_listed_and_updated_only_within_the_users_location(): void
    {
        $acc = $this->vehicleAt($this->sowutuom, 'ACC-1');
        $kum = $this->vehicleAt($this->kumasi, 'KUM-1');
        $own = $this->issue($acc, ['status' => VehicleIssue::STATUS_OPEN]);
        $foreign = $this->issue($kum, ['status' => VehicleIssue::STATUS_OPEN]);
        $manager = $this->manager('M1', $this->sowutuom);

        Livewire::actingAs($manager)->test(Issues::class)->assertSee('ACC-1')->assertDontSee('KUM-1');

        Livewire::actingAs($manager)->test(Issues::class)->call('updateStatus', $foreign->id, 'resolved')->assertNotFound();
        $this->assertSame(VehicleIssue::STATUS_OPEN, $foreign->fresh()->status);

        Livewire::actingAs($manager)->test(Issues::class)->call('updateStatus', $own->id, 'resolved')->assertOk();
        $this->assertSame(VehicleIssue::STATUS_RESOLVED, $own->fresh()->status);
    }

    public function test_a_drivers_own_history_on_a_vehicle_that_left_their_location_is_hidden_not_leaked_by_an_or_clause(): void
    {
        $driver = $this->driver('D1', $this->sowutuom);
        // Still assigned to the driver (both columns the "mine" clauses look at), but based elsewhere now.
        $left = $this->vehicleAt($this->kumasi, 'KUM-LEFT', ['is_pool_car' => false, 'assigned_user_id' => $driver->id, 'assigned_driver_id' => $driver->id, 'driver_type' => Vehicle::DRIVER_ASSIGNED]);
        $mine = $this->vehicleAt($this->sowutuom, 'ACC-MINE');
        $this->issue($left, ['reported_by' => $driver->id]);
        $this->mileageLog($left, ['driver_id' => $driver->id]);
        $this->issue($mine, ['reported_by' => $driver->id]);

        Livewire::actingAs($driver)->test(Issues::class)->assertSee('ACC-MINE')->assertDontSee('KUM-LEFT');
        Livewire::actingAs($driver)->test(Mileage::class)->assertDontSee('KUM-LEFT');
    }

    public function test_a_driver_cannot_log_mileage_or_report_issues_on_a_vehicle_by_changing_the_id(): void
    {
        $driver = $this->driver('D1', $this->sowutuom);
        $mine = $this->vehicleAt($this->sowutuom, 'ACC-MINE', ['assigned_driver_id' => $driver->id, 'driver_type' => Vehicle::DRIVER_ASSIGNED]);
        $colleague = $this->vehicleAt($this->sowutuom, 'ACC-OTHER');
        $foreign = $this->vehicleAt($this->kumasi, 'KUM-1');

        foreach ([$colleague, $foreign] as $vehicle) {
            Livewire::actingAs($driver)->test(Mileage::class)->set('vehicleId', $vehicle->id)->assertNotFound();
            Livewire::actingAs($driver)->test(Issues::class)
                ->set('vehicleId', $vehicle->id)
                ->set('form', ['issue_types' => ['engine'], 'severity' => 'low', 'description' => 'Noise'])
                ->call('submit')
                ->assertNotFound();
        }

        Livewire::actingAs($driver)->test(Mileage::class)
            ->assertSet('vehicleId', $mine->id)
            ->set('form.mileage_after', $mine->current_mileage + 25)
            ->set('form.trip_purpose', 'Field visit')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, MileageLog::query()->count());
    }

    // ---------------------------------------------------------------- reports and exports

    public function test_report_figures_and_their_cache_are_per_location(): void
    {
        $acc = $this->vehicleAt($this->sowutuom, 'ACC-1');
        $ho = $this->vehicleAt($this->headOffice, 'HO-1');
        $kum = $this->vehicleAt($this->kumasi, 'KUM-1');
        $this->expense($acc, ['amount' => 100, 'expense_date' => today()->toDateString()]);
        $this->expense($ho, ['amount' => 7, 'expense_date' => today()->toDateString()]);
        $this->expense($kum, ['amount' => 1000, 'expense_date' => today()->toDateString()]);

        $reports = app(ReportsService::class);
        [$from, $to] = $reports->resolveDateRange('this_month');
        $total = fn ($user) => $reports->reportPayload($from, $to, null, $user)['monthlyExpenses']['total'];

        // Asked in turn, so a cached figure would show up as the wrong number for the next person.
        $this->assertEquals(100, $total($this->manager('M1', $this->sowutuom)));
        $this->assertEquals(7, $total($this->manager('M3', $this->headOffice)));
        $this->assertEquals(1000, $total($this->manager('M4', $this->kumasi)));
        $this->assertEquals(1107, $total($this->superAdmin()));
        $this->assertEquals(0, $total($this->homeless('H1', ['transport_manager'])));
        $this->assertEquals(100, $total($this->manager('M5', $this->odorkor)));

        $payload = $reports->reportPayload($from, $to, null, $this->manager('M1', $this->sowutuom));
        $this->assertSame(['ACC-1'], $payload['topExpensiveVehicles']['labels']);
        $this->assertSame(1, $payload['vehicleStatusCounts']['data'][0]);
    }

    public function test_the_reports_screen_and_its_pdf_and_excel_downloads_are_scoped(): void
    {
        $acc = $this->vehicleAt($this->sowutuom, 'ACC-1');
        $kum = $this->vehicleAt($this->kumasi, 'KUM-1');
        $this->expense($acc, ['amount' => 321, 'expense_date' => today()->toDateString()]);
        $this->expense($kum, ['amount' => 987, 'expense_date' => today()->toDateString()]);
        $manager = $this->manager('M1', $this->sowutuom);

        Livewire::actingAs($manager)->test(Reports::class)
            ->assertViewHas('exportQuery')
            ->assertSet('payload.monthlyExpenses.total', 321.0);

        $this->actingAs($manager)->get(route('transport.reports.export.excel'))->assertOk();

        $pdf = $this->actingAs($manager)->get(route('transport.reports.export.pdf'));
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
    }

    public function test_the_expense_export_lists_only_reachable_vehicles_and_404s_a_foreign_filter(): void
    {
        $acc = $this->vehicleAt($this->sowutuom, 'ACC-1');
        $kum = $this->vehicleAt($this->kumasi, 'KUM-1');
        $this->expense($acc, []);
        $this->expense($kum, []);
        $manager = $this->manager('M1', $this->sowutuom);

        $this->assertSame(['ACC-1'], (new VehicleExpensesExport($manager))->collection()->pluck('vehicle')->all());
        // Even a filter naming a foreign vehicle (as a direct caller might pass) yields nothing from the export itself.
        $this->assertSame([], (new VehicleExpensesExport($manager, ['vehicle_id' => $kum->id]))->collection()->all());
        $this->assertCount(2, (new VehicleExpensesExport($this->superAdmin()))->collection());

        $this->actingAs($manager)->get(route('transport.expenses.export'))->assertOk();
        $this->actingAs($manager)->get(route('transport.expenses.export', ['vehicle_id' => $acc->id]))->assertOk();
        $this->actingAs($manager)->get(route('transport.expenses.export', ['vehicle_id' => $kum->id]))->assertNotFound();
        $this->actingAs($manager)->get(route('transport.expenses.export', ['vehicle_id' => 999999]))->assertNotFound();
    }

    // ---------------------------------------------------------------- driver API

    public function test_the_driver_api_serves_a_driver_only_for_a_vehicle_inside_their_location(): void
    {
        $inside = $this->driver('D1', $this->sowutuom, ['api_token' => 'inside-token']);
        $moved = $this->driver('D2', $this->kumasi, ['api_token' => 'moved-token']);
        $homeless = $this->homeless('D3', ['driver']);
        $homeless->forceFill(['api_token' => 'homeless-token'])->save();

        $vehicle = $this->vehicleAt($this->sowutuom, 'ACC-1', ['assigned_driver_id' => $inside->id, 'driver_type' => Vehicle::DRIVER_ASSIGNED, 'current_mileage' => 1000]);
        $stranded = $this->vehicleAt($this->sowutuom, 'ACC-2', ['assigned_driver_id' => $moved->id, 'driver_type' => Vehicle::DRIVER_ASSIGNED, 'current_mileage' => 2000]);
        $unplaced = $this->unplacedVehicle('NOPL-1', ['assigned_driver_id' => $homeless->id, 'driver_type' => Vehicle::DRIVER_ASSIGNED, 'current_mileage' => 3000]);

        $this->withToken('inside-token')->getJson(route('api.transport.my-vehicle'))->assertOk()->assertJsonPath('vehicle.number_plate', 'ACC-1');
        $this->withToken('inside-token')->postJson(route('api.transport.mileage-logs.store'), [
            'mileage_after' => 1100, 'trip_date' => today()->toDateString(), 'trip_purpose' => 'Field visit',
        ])->assertCreated();
        $this->assertSame(1100, $vehicle->fresh()->current_mileage);

        // Assigned to a vehicle of another location (or an unplaced one): no vehicle, nothing written.
        foreach (['moved-token' => $stranded, 'homeless-token' => $unplaced] as $token => $hidden) {
            $this->withToken($token)->getJson(route('api.transport.my-vehicle'))->assertOk()->assertJsonPath('vehicle', null);
            $this->withToken($token)->postJson(route('api.transport.mileage-logs.store'), [
                'mileage_after' => $hidden->current_mileage + 50, 'trip_date' => today()->toDateString(), 'trip_purpose' => 'Field visit',
            ])->assertUnprocessable();
            $this->withToken($token)->postJson(route('api.transport.vehicle-issues.store'), [
                'issue_types' => ['engine'], 'severity' => 'low', 'description' => 'Noise',
            ])->assertUnprocessable();
            $this->assertSame($hidden->current_mileage, $hidden->fresh()->current_mileage);
        }

        $this->assertSame(1, MileageLog::query()->count());
        $this->assertSame(0, VehicleIssue::query()->count());
    }
}
