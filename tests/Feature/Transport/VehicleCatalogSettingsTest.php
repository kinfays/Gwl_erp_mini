<?php

namespace Tests\Feature\Transport;

use App\Livewire\Transport\Settings\VehicleBrandsManager;
use App\Livewire\Transport\Settings\VehicleModelsManager;
use App\Livewire\Transport\Vehicles;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use App\Models\VehicleModel;
use App\Services\Transport\VehicleCatalogService;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

/**
 * Transport > Settings > Vehicle Brands / Models: the permission, the duplicate rule, and the promise that a rename or a
 * move keeps vehicles.brand / vehicles.model (plain text) in step, soft-deleted vehicles included.
 */
class VehicleCatalogSettingsTest extends TransportTestCase
{
    private function brand(string $name, bool $active = true): VehicleBrand
    {
        return VehicleBrand::create(['name' => $name, 'is_active' => $active]);
    }

    private function model(VehicleBrand $brand, string $name, bool $active = true): VehicleModel
    {
        return VehicleModel::create(['vehicle_brand_id' => $brand->id, 'name' => $name, 'is_active' => $active]);
    }

    private function car(string $plate, string $brand, string $model): Vehicle
    {
        return $this->vehicleAt($this->sowutuom, $plate, ['brand' => $brand, 'model' => $model]);
    }

    /** A transport manager who can use the vehicle form but does not hold the catalogue permission. */
    private function managerWithoutCatalogPermission(): \App\Models\User
    {
        $permission = Permission::query()->where('name', VehicleCatalogService::PERMISSION)->firstOrFail();
        Role::query()->where('name', 'transport_manager')->firstOrFail()->permissions()->detach($permission->id);

        return $this->manager('NOCAT', $this->sowutuom);
    }

    // ------------------------------------------------------------------ permission

    public function test_the_permission_is_granted_to_super_admin_and_transport_manager_only(): void
    {
        $permission = Permission::query()->where('name', VehicleCatalogService::PERMISSION)->firstOrFail();

        $this->assertSame(Permission::MODULE_TRANSPORT, $permission->module);
        $this->assertEqualsCanonicalizing(
            ['super_admin', 'transport_manager'],
            $permission->roles()->pluck('name')->all()
        );
    }

    public function test_the_settings_routes_need_the_catalogue_permission(): void
    {
        foreach (['transport.settings.brands', 'transport.settings.models'] as $route) {
            $this->actingAs($this->manager('M1', $this->sowutuom))->get(route($route))->assertOk();
            $this->actingAs($this->superAdmin())->get(route($route))->assertOk();
            $this->actingAs($this->driver('D1', $this->sowutuom))->get(route($route))->assertForbidden();
            $this->actingAs($this->person('E1', $this->sowutuom))->get(route($route))->assertForbidden();
        }
    }

    public function test_the_screens_refuse_a_user_without_the_permission_even_when_mounted_directly(): void
    {
        $user = $this->managerWithoutCatalogPermission();

        Livewire::actingAs($user)->test(VehicleBrandsManager::class)->assertForbidden();
        Livewire::actingAs($user)->test(VehicleModelsManager::class)->assertForbidden();
        $this->actingAs($user)->get(route('transport.settings.brands'))->assertForbidden();
    }

    public function test_the_service_refuses_a_user_without_the_permission(): void
    {
        $brand = $this->brand('Toyota');
        Auth::login($this->managerWithoutCatalogPermission());

        foreach ([
            fn () => app(VehicleCatalogService::class)->createBrand('Kia'),
            fn () => app(VehicleCatalogService::class)->updateBrand($brand, 'Toyotas'),
            fn () => app(VehicleCatalogService::class)->setBrandActive($brand, false),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected a 403.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }

        $this->assertSame('Toyota', $brand->fresh()->name);
        $this->assertTrue($brand->fresh()->is_active);
    }

    public function test_the_sidebar_shows_settings_only_to_holders_of_the_permission(): void
    {
        $this->actingAs($this->manager('M1', $this->sowutuom))->get(route('transport.home'))
            ->assertOk()->assertSee('Vehicle Brands')->assertSee('Vehicle Models');

        $this->actingAs($this->driver('D1', $this->sowutuom))->get(route('transport.home'))
            ->assertOk()->assertDontSee('Vehicle Brands')->assertDontSee('Vehicle Models');
    }

    public function test_the_add_new_panels_on_the_vehicle_form_are_for_holders_only(): void
    {
        $brand = $this->brand('Toyota');

        Livewire::actingAs($this->manager('M1', $this->sowutuom))->test(Vehicles::class)
            ->call('openCreate')->set('brandId', $brand->id)
            ->assertSee('Add new brand')->assertSee('Add new model');

        $user = $this->managerWithoutCatalogPermission();

        $form = Livewire::actingAs($user)->test(Vehicles::class)
            ->call('openCreate')->set('brandId', $brand->id)
            ->assertDontSee('Add new brand')->assertDontSee('Add new model');

        // The actions refuse too, not just the buttons.
        $form->call('openNewBrand')->assertForbidden();
        Livewire::actingAs($user)->test(Vehicles::class)->call('openCreate')->set('brandId', $brand->id)
            ->set('newModel.name', 'Hilux')->call('saveNewModel')->assertForbidden();

        $this->assertSame(0, VehicleModel::query()->count());
    }

    // ------------------------------------------------------------------ duplicates

    public function test_a_duplicate_brand_is_rejected_case_insensitively(): void
    {
        $this->brand('Toyota');
        $other = $this->brand('Nissan');

        Livewire::actingAs($this->manager('M1', $this->sowutuom))->test(VehicleBrandsManager::class)
            ->set('name', 'tOYOTA')->call('save')->assertHasErrors(['name'])
            ->set('name', 'Kia')->call('save')->assertHasNoErrors();

        Livewire::actingAs($this->manager('M1', $this->sowutuom))->test(VehicleBrandsManager::class)
            ->call('edit', $other->id)->set('editingName', ' TOYOTA ')->call('update')->assertHasErrors(['editingName']);

        $this->assertSame('Nissan', $other->fresh()->name);
        $this->assertSame(1, VehicleBrand::query()->whereRaw('LOWER(name) = ?', ['toyota'])->count());
    }

    public function test_a_duplicate_model_is_rejected_within_a_brand_but_allowed_in_another(): void
    {
        $toyota = $this->brand('Toyota');
        $nissan = $this->brand('Nissan');
        $hilux = $this->model($toyota, 'Hilux');
        $navara = $this->model($toyota, 'Navara');

        $screen = Livewire::actingAs($this->manager('M1', $this->sowutuom))->test(VehicleModelsManager::class);

        $screen->set('brandId', $toyota->id)->set('name', 'HILUX')->call('save')->assertHasErrors(['name']);
        $screen->set('brandId', $nissan->id)->set('name', 'Hilux')->call('save')->assertHasNoErrors();

        $screen->call('edit', $navara->id)->set('editingName', 'hilux')->call('update')->assertHasErrors(['editingName']);

        $this->assertSame('Navara', $navara->fresh()->name);
        $this->assertSame(1, VehicleModel::query()->where('vehicle_brand_id', $toyota->id)->whereRaw('LOWER(name) = ?', ['hilux'])->count());
        $this->assertSame($toyota->id, $hilux->fresh()->vehicle_brand_id);
    }

    public function test_a_rename_may_keep_its_own_name_or_only_change_its_case(): void
    {
        $brand = $this->brand('toyota');
        $car = $this->car('ACC-1', 'toyota', 'Hilux');

        Livewire::actingAs($this->manager('M1', $this->sowutuom))->test(VehicleBrandsManager::class)
            ->call('edit', $brand->id)->set('editingName', 'toyota')->call('update')->assertHasNoErrors();
        $this->assertSame('toyota', $brand->fresh()->name);

        Livewire::actingAs($this->manager('M1', $this->sowutuom))->test(VehicleBrandsManager::class)
            ->call('edit', $brand->id)->set('editingName', 'Toyota')->call('update')->assertHasNoErrors();

        $this->assertSame('Toyota', $brand->fresh()->name);
        $this->assertSame('Toyota', $car->fresh()->brand);
    }

    // ------------------------------------------------------------------ rename and move keep vehicles consistent

    public function test_renaming_a_brand_rewrites_matching_vehicles_including_soft_deleted_ones(): void
    {
        $toyota = $this->brand('Toyota');
        $this->brand('Nissan');

        $live = $this->car('ACC-1', 'Toyota', 'Hilux');
        $spelled = $this->car('ACC-2', ' toyota ', 'Corolla');      // case / spacing variants still match
        $deleted = $this->car('ACC-3', 'TOYOTA', 'Hilux');
        $deleted->delete();
        $other = $this->car('ACC-4', 'Nissan', 'Navara');

        $this->assertTrue($deleted->fresh()?->trashed() ?? Vehicle::withTrashed()->find($deleted->id)->trashed());

        $screen = Livewire::actingAs($this->manager('M1', $this->sowutuom))->test(VehicleBrandsManager::class);

        // The confirm step is told how many vehicles will change (soft-deleted included).
        $screen->call('edit', $toyota->id)->assertViewHas('editingVehicleCount', 3);

        $screen->set('editingName', 'Toyota Motors')->call('update')->assertHasNoErrors();

        $this->assertSame('Toyota Motors', $toyota->fresh()->name);
        $this->assertSame('Toyota Motors', $live->fresh()->brand);
        $this->assertSame('Toyota Motors', $spelled->fresh()->brand);
        $this->assertSame('Toyota Motors', Vehicle::withTrashed()->find($deleted->id)->brand);
        $this->assertSame('Nissan', $other->fresh()->brand);

        // The model text is left alone by a brand rename.
        $this->assertSame('Hilux', $live->fresh()->model);

        $audit = AuditLog::query()->where('action', 'update_vehicle_brand')->latest('id')->firstOrFail();
        $this->assertSame(Permission::MODULE_TRANSPORT, $audit->module);
        $this->assertSame('Toyota', $audit->old_values['name']);
        $this->assertSame('Toyota Motors', $audit->new_values['name']);
        $this->assertSame(3, $audit->new_values['vehicles_updated']);
    }

    public function test_renaming_a_model_rewrites_only_vehicles_with_that_brand_and_model(): void
    {
        $toyota = $this->brand('Toyota');
        $nissan = $this->brand('Nissan');
        $hilux = $this->model($toyota, 'Hilux');
        $this->model($nissan, 'Hilux');

        $match = $this->car('ACC-1', 'Toyota', 'hilux');
        $deleted = $this->car('ACC-2', 'Toyota', 'Hilux');
        $deleted->delete();
        $sameNameOtherBrand = $this->car('ACC-3', 'Nissan', 'Hilux');
        $otherModel = $this->car('ACC-4', 'Toyota', 'Corolla');

        $screen = Livewire::actingAs($this->manager('M1', $this->sowutuom))->test(VehicleModelsManager::class);
        $screen->call('edit', $hilux->id)->assertViewHas('editingVehicleCount', 2);
        $screen->set('editingName', 'Hilux Double Cab')->call('update')->assertHasNoErrors();

        $this->assertSame('Hilux Double Cab', $hilux->fresh()->name);
        $this->assertSame('Hilux Double Cab', $match->fresh()->model);
        $this->assertSame('Hilux Double Cab', Vehicle::withTrashed()->find($deleted->id)->model);
        $this->assertSame('Hilux', $sameNameOtherBrand->fresh()->model);
        $this->assertSame('Corolla', $otherModel->fresh()->model);
        $this->assertSame('Toyota', $match->fresh()->brand);

        $audit = AuditLog::query()->where('action', 'update_vehicle_model')->latest('id')->firstOrFail();
        $this->assertSame('Hilux', $audit->old_values['name']);
        $this->assertSame('Hilux Double Cab', $audit->new_values['name']);
        $this->assertSame(2, $audit->new_values['vehicles_updated']);
    }

    public function test_moving_a_model_to_another_brand_rewrites_the_brand_text_of_its_vehicles(): void
    {
        $toyota = $this->brand('Toyota');
        $lexus = $this->brand('Lexus');
        $rx = $this->model($toyota, 'RX');

        $live = $this->car('ACC-1', 'Toyota', 'RX');
        $deleted = $this->car('ACC-2', 'TOYOTA', 'rx');
        $deleted->delete();
        $stays = $this->car('ACC-3', 'Toyota', 'Corolla');

        $screen = Livewire::actingAs($this->manager('M1', $this->sowutuom))->test(VehicleModelsManager::class);
        $screen->call('edit', $rx->id)->set('editingBrandId', $lexus->id)->call('update')->assertHasNoErrors();

        $this->assertSame($lexus->id, $rx->fresh()->vehicle_brand_id);
        $this->assertSame(['Lexus', 'RX'], [$live->fresh()->brand, $live->fresh()->model]);
        $trashed = Vehicle::withTrashed()->find($deleted->id);
        $this->assertSame(['Lexus', 'RX'], [$trashed->brand, $trashed->model]);
        $this->assertSame(['Toyota', 'Corolla'], [$stays->fresh()->brand, $stays->fresh()->model]);

        $audit = AuditLog::query()->where('action', 'update_vehicle_model')->latest('id')->firstOrFail();
        $this->assertSame('Toyota', $audit->old_values['brand_name']);
        $this->assertSame('Lexus', $audit->new_values['brand_name']);
        $this->assertSame(2, $audit->new_values['vehicles_updated']);
    }

    public function test_a_move_into_a_brand_that_already_has_that_model_is_refused_and_changes_nothing(): void
    {
        $toyota = $this->brand('Toyota');
        $lexus = $this->brand('Lexus');
        $rx = $this->model($toyota, 'RX');
        $this->model($lexus, 'RX');
        $car = $this->car('ACC-1', 'Toyota', 'RX');

        Livewire::actingAs($this->manager('M1', $this->sowutuom))->test(VehicleModelsManager::class)
            ->call('edit', $rx->id)->set('editingBrandId', $lexus->id)->call('update')->assertHasErrors(['editingName']);

        $this->assertSame($toyota->id, $rx->fresh()->vehicle_brand_id);
        $this->assertSame('Toyota', $car->fresh()->brand);
        $this->assertSame(0, AuditLog::query()->where('action', 'update_vehicle_model')->count());
    }

    public function test_a_failed_rename_leaves_vehicles_and_catalogue_untouched(): void
    {
        $toyota = $this->brand('Toyota');
        $car = $this->car('ACC-1', 'Toyota', 'Hilux');
        Auth::login($this->manager('M1', $this->sowutuom));

        // Same transaction: when the catalogue write fails, the vehicles are rolled back with it.
        VehicleBrand::updating(function (): void {
            throw new \RuntimeException('boom');
        });

        try {
            app(VehicleCatalogService::class)->updateBrand($toyota, 'Toyota Motors');
            $this->fail('Expected the failure to surface.');
        } catch (\RuntimeException) {
            // expected
        } finally {
            VehicleBrand::flushEventListeners();
        }

        $this->assertSame('Toyota', $toyota->fresh()->name);
        $this->assertSame('Toyota', $car->fresh()->brand);
    }

    // ------------------------------------------------------------------ deactivating

    public function test_deactivating_a_brand_keeps_vehicles_intact_and_hides_it_from_new_vehicles_only(): void
    {
        $toyota = $this->brand('Toyota');
        $this->brand('Nissan');
        $hilux = $this->model($toyota, 'Hilux');
        $car = $this->car('ACC-1', 'Toyota', 'Hilux');
        $manager = $this->manager('M1', $this->sowutuom);

        Livewire::actingAs($manager)->test(VehicleBrandsManager::class)->call('toggleActive', $toyota->id);

        $this->assertFalse($toyota->fresh()->is_active);
        $this->assertSame(['Toyota', 'Hilux'], [$car->fresh()->brand, $car->fresh()->model]);
        $this->assertTrue($hilux->fresh()->is_active);

        $labels = fn (array $options) => collect($options)->pluck('label')->all();

        // A new vehicle can no longer pick it ...
        Livewire::actingAs($manager)->test(Vehicles::class)->call('openCreate')
            ->assertViewHas('brandOptions', fn (array $options) => $labels($options) === ['Nissan']);

        // ... but the existing vehicle keeps it selectable while it is edited.
        Livewire::actingAs($manager)->test(Vehicles::class)->call('openEdit', $car->id)
            ->assertViewHas('brandOptions', fn (array $options) => in_array('Toyota', $labels($options), true))
            ->assertViewHas('modelOptions', fn (array $options) => $labels($options) === ['Hilux']);

        $audit = AuditLog::query()->where('action', 'deactivate_vehicle_brand')->firstOrFail();
        $this->assertTrue($audit->old_values['is_active']);
        $this->assertFalse($audit->new_values['is_active']);

        // And it can be switched back on.
        Livewire::actingAs($manager)->test(VehicleBrandsManager::class)->call('toggleActive', $toyota->id);
        $this->assertTrue($toyota->fresh()->is_active);
        $this->assertSame(1, AuditLog::query()->where('action', 'activate_vehicle_brand')->count());
    }

    public function test_deactivating_a_model_keeps_vehicles_intact_and_hides_it_from_new_vehicles_only(): void
    {
        $toyota = $this->brand('Toyota');
        $hilux = $this->model($toyota, 'Hilux');
        $this->model($toyota, 'Corolla');
        $car = $this->car('ACC-1', 'Toyota', 'Hilux');
        $manager = $this->manager('M1', $this->sowutuom);

        Livewire::actingAs($manager)->test(VehicleModelsManager::class)->call('toggleActive', $hilux->id);

        $this->assertFalse($hilux->fresh()->is_active);
        $this->assertSame('Hilux', $car->fresh()->model);

        $labels = fn (array $options) => collect($options)->pluck('label')->all();

        Livewire::actingAs($manager)->test(Vehicles::class)->call('openCreate')->set('brandId', $toyota->id)
            ->assertViewHas('modelOptions', fn (array $options) => $labels($options) === ['Corolla']);

        Livewire::actingAs($manager)->test(Vehicles::class)->call('openEdit', $car->id)
            ->assertViewHas('modelOptions', fn (array $options) => in_array('Hilux', $labels($options), true));

        $this->assertSame(1, AuditLog::query()->where('action', 'deactivate_vehicle_model')->count());
    }

    public function test_a_model_cannot_be_added_or_moved_to_an_inactive_brand(): void
    {
        $active = $this->brand('Toyota');
        $inactive = $this->brand('Old Brand', false);
        $model = $this->model($active, 'Hilux');

        $screen = Livewire::actingAs($this->manager('M1', $this->sowutuom))->test(VehicleModelsManager::class);

        $screen->set('brandId', $inactive->id)->set('name', 'Anything')->call('save')->assertHasErrors(['brandId']);
        $screen->call('edit', $model->id)->set('editingBrandId', $inactive->id)->call('update')->assertHasErrors(['editingBrandId']);

        $this->assertSame($active->id, $model->fresh()->vehicle_brand_id);
        $this->assertSame(0, VehicleModel::query()->where('name', 'Anything')->count());
    }

    // ------------------------------------------------------------------ screens and audit

    public function test_the_lists_search_filter_and_count_vehicles(): void
    {
        $toyota = $this->brand('Toyota');
        $nissan = $this->brand('Nissan');
        $this->model($toyota, 'Hilux');
        $this->model($nissan, 'Navara');
        $this->car('ACC-1', 'Toyota', 'Hilux');
        $gone = $this->car('ACC-2', 'Toyota', 'Hilux');
        $gone->delete();

        $manager = $this->manager('M1', $this->sowutuom);

        Livewire::actingAs($manager)->test(VehicleBrandsManager::class)
            ->assertSee('Toyota')->assertSee('Nissan')
            ->assertViewHas('vehicleCounts', fn (array $counts) => $counts[$toyota->id] === 2 && $counts[$nissan->id] === 0)
            ->set('search', 'niss')->assertSee('Edit Nissan')->assertDontSee('Edit Toyota');

        Livewire::actingAs($manager)->test(VehicleModelsManager::class)
            ->assertSee('Hilux')->assertSee('Navara')
            ->set('filterBrandId', $nissan->id)->assertSee('Edit Navara')->assertDontSee('Edit Hilux')
            ->set('filterBrandId', null)->set('search', 'hil')->assertSee('Edit Hilux')->assertDontSee('Edit Navara');
    }

    public function test_creating_through_the_screens_writes_audit_rows(): void
    {
        $manager = $this->manager('M1', $this->sowutuom);

        Livewire::actingAs($manager)->test(VehicleBrandsManager::class)->set('name', 'Isuzu')->call('save')->assertHasNoErrors();
        $isuzu = VehicleBrand::query()->where('name', 'Isuzu')->firstOrFail();

        Livewire::actingAs($manager)->test(VehicleModelsManager::class)
            ->set('brandId', $isuzu->id)->set('name', 'D-Max')->call('save')->assertHasNoErrors();

        $this->assertSame(1, AuditLog::query()->where('action', 'create_vehicle_brand')->where('target_id', $isuzu->id)->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'create_vehicle_model')->count());
        $this->assertSame(Permission::MODULE_TRANSPORT, AuditLog::query()->where('action', 'create_vehicle_model')->value('module'));
    }

    public function test_the_vehicle_form_panels_still_create_through_the_service_for_holders(): void
    {
        $toyota = $this->brand('Toyota');

        Livewire::actingAs($this->manager('M1', $this->sowutuom))->test(Vehicles::class)
            ->call('openCreate')
            ->set('newBrand.name', 'toyota')->call('saveNewBrand')->assertHasErrors(['newBrand.name'])
            ->set('newBrand.name', 'Kia')->call('saveNewBrand')->assertHasNoErrors();

        $this->assertSame(1, VehicleBrand::query()->where('name', 'Kia')->count());
        $this->assertSame(1, VehicleBrand::query()->where('id', $toyota->id)->count());
    }
}
