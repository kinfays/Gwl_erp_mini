<?php

namespace Tests\Feature\Assets;

use App\Livewire\Assets\Settings\ManufacturersManager;
use App\Models\IctAssetManufacturer;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AssetsRolePermissionSeeder;
use Database\Seeders\ModuleAccessSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class ManufacturersSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PermissionSeeder::class, ModuleAccessSeeder::class, AssetsRolePermissionSeeder::class]);

        $user = User::query()->create([
            'staff_id' => 'SA001', 'full_name' => 'SA', 'email' => 'sa001@example.com',
            'password' => Hash::make('password'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->roles()->attach(Role::query()->where('name', 'super_admin')->firstOrFail());
        $this->actingAs($user);

        foreach ([['HP', null], ['Dell', 'Laptops'], ['Cisco', null], ['100% Tech', null], ['A_B Corp', null], ['AxB Corp', null]] as [$name, $notes]) {
            IctAssetManufacturer::query()->create(['name' => $name, 'notes' => $notes, 'is_active' => true]);
        }
    }

    private function names($component): array
    {
        return $component->viewData('manufacturers')->pluck('name')->sort()->values()->all();
    }

    public function test_search_filters_by_name_and_notes(): void
    {
        $this->assertSame(['Cisco'], $this->names(Livewire::test(ManufacturersManager::class)->set('search', 'cis')));
        $this->assertSame(['Dell'], $this->names(Livewire::test(ManufacturersManager::class)->set('search', 'laptop')));
        $this->assertCount(6, Livewire::test(ManufacturersManager::class)->viewData('manufacturers'));
    }

    public function test_percent_and_underscore_are_plain_text(): void
    {
        $this->assertSame(['100% Tech'], $this->names(Livewire::test(ManufacturersManager::class)->set('search', '%')));
        $this->assertSame(['A_B Corp'], $this->names(Livewire::test(ManufacturersManager::class)->set('search', 'A_B')));
    }

    public function test_empty_state_and_clear_button(): void
    {
        Livewire::test(ManufacturersManager::class)
            ->set('search', 'zzzz')
            ->assertSee('No manufacturers match your search')
            ->assertSee('Clear search')
            ->set('search', '')
            ->assertDontSee('No manufacturers match your search')
            ->assertDontSee('Clear search');
    }

    public function test_search_is_bound_to_the_url(): void
    {
        Livewire::withQueryParams(['q' => 'dell'])
            ->test(ManufacturersManager::class)
            ->assertSet('search', 'dell')
            ->assertSee('Dell')
            ->assertDontSee('Cisco');
    }
}
