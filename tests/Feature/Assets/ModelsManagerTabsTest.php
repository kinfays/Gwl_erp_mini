<?php

namespace Tests\Feature\Assets;

use App\Livewire\Assets\Settings\ModelsManager;
use App\Models\IctAssetManufacturer;
use App\Models\IctAssetModel;
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

class ModelsManagerTabsTest extends TestCase
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

        $hp = IctAssetManufacturer::query()->create(['name' => 'HP', 'is_active' => true]);
        $cisco = IctAssetManufacturer::query()->create(['name' => 'Cisco', 'is_active' => true]);

        foreach ([
            ['HP ProDesk', 'PC', $hp], ['HP EliteBook', 'Laptop', $hp], ['Dell Tower', 'Server', null],
            ['HP LaserJet', 'PRT', $hp], ['Zebra Label', 'TPR', null], ['Glory Counter', 'CM', null],
            ['Samsung A14', 'Ph', null], ['Cisco ISR', 'RT', $cisco],
        ] as [$name, $category, $maker]) {
            IctAssetModel::query()->create([
                'name' => $name, 'category' => $category, 'is_active' => true,
                'ict_asset_manufacturer_id' => $maker?->id,
            ]);
        }
    }

    private function names($component): array
    {
        return $component->viewData('models')->pluck('name')->sort()->values()->all();
    }

    public function test_each_tab_returns_only_its_mapped_types(): void
    {
        $c = Livewire::test(ModelsManager::class);

        $this->assertSame(8, $c->viewData('models')->total());
        $this->assertSame(['Dell Tower', 'HP EliteBook', 'HP ProDesk'], $this->names($c->set('tab', 'computers')));
        $this->assertSame(['Glory Counter', 'HP LaserJet', 'Zebra Label'], $this->names($c->set('tab', 'printers')));
        $this->assertSame(['Samsung A14'], $this->names($c->set('tab', 'phones')));
        $this->assertSame(['Cisco ISR'], $this->names($c->set('tab', 'network')));
    }

    public function test_tab_counts_come_from_the_whole_catalogue(): void
    {
        $counts = Livewire::test(ModelsManager::class)->set('search', 'cisco')->viewData('tabCounts');

        $this->assertSame(['all' => 8, 'computers' => 3, 'printers' => 3, 'phones' => 1, 'network' => 1], $counts);
    }

    public function test_search_matches_name_and_manufacturer_within_the_active_tab_and_survives_a_tab_switch(): void
    {
        $c = Livewire::test(ModelsManager::class)->set('tab', 'computers')->set('search', 'hp');

        $this->assertSame(['HP EliteBook', 'HP ProDesk'], $this->names($c));

        $c->set('tab', 'printers');
        $this->assertSame('hp', $c->get('search'));
        $this->assertSame(['HP LaserJet'], $this->names($c));

        $this->assertSame(['Cisco ISR'], $this->names($c->set('tab', 'network')->set('search', 'Cisco')));
        $this->assertSame([], $this->names($c->set('tab', 'computers')));
    }

    public function test_tab_and_search_are_bound_to_the_url(): void
    {
        Livewire::withQueryParams(['tab' => 'phones', 'q' => 'samsung'])
            ->test(ModelsManager::class)
            ->assertSet('tab', 'phones')
            ->assertSet('search', 'samsung')
            ->assertSee('Samsung A14')
            ->assertDontSee('Cisco ISR');
    }

    public function test_an_unknown_tab_falls_back_to_all(): void
    {
        $this->assertSame(8, Livewire::withQueryParams(['tab' => 'bogus'])->test(ModelsManager::class)->viewData('models')->total());
    }

    public function test_empty_state_when_nothing_matches(): void
    {
        Livewire::test(ModelsManager::class)
            ->set('search', 'zzzz-nothing')
            ->assertSee('No models found.')
            ->assertSee('Try a different search or tab.');
    }

    public function test_the_list_is_paginated_and_a_tab_or_search_change_returns_to_page_one(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            IctAssetModel::query()->create(['name' => sprintf('Bulk PC %02d', $i), 'category' => 'PC', 'is_active' => true]);
        }

        $c = Livewire::test(ModelsManager::class)->set('tab', 'computers');

        $this->assertSame(15, $c->viewData('models')->count());
        $this->assertSame(23, $c->viewData('models')->total());

        $c->call('gotoPage', 2);
        $this->assertSame(2, $c->viewData('models')->currentPage());

        $c->set('search', 'bulk');
        $this->assertSame(1, $c->viewData('models')->currentPage());

        $c->call('gotoPage', 2)->set('tab', 'printers');
        $this->assertSame(1, $c->viewData('models')->currentPage());
    }
}
