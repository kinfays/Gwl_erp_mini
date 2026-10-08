<?php

namespace Tests\Feature\HealthSafety;

use App\Livewire\HealthSafety\PpeEntitlements;
use App\Livewire\HealthSafety\PpeReorderLevels;
use App\Livewire\HealthSafety\PpeTypes;
use App\Livewire\HealthSafety\Sites;
use App\Models\AuditLog;
use App\Models\HsPpeEntitlement;
use App\Models\HsPpeReorderLevel;
use App\Models\HsPpeType;
use App\Models\JobTitle;
use App\Services\HealthSafety\PpeSetupService;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PpeSetupTest extends HealthSafetyTestCase
{
    private function config(): PpeSetupService
    {
        return app(PpeSetupService::class);
    }

    private function officer0()
    {
        return $this->officer('200001', $this->accraWest, $this->headOffice);
    }

    // ---------------------------------------------------------------- types

    public function test_a_type_is_saved_with_its_sizes_service_life_and_expiry_flag(): void
    {
        $type = $this->config()->saveType($this->officer0(), null, [
            'name' => 'Safety boots', 'category' => 'foot', 'has_sizes' => true, 'sizes' => '38, 39 , 40;41, 39', 'replacement_months' => '12', 'has_expiry' => false, 'unit' => 'pair',
        ]);

        $this->assertSame(['38', '39', '40', '41'], $type->sizes, 'trimmed, split on commas and semicolons, no repeats');
        $this->assertSame(12, $type->replacement_months);
        $this->assertSame('pair', $type->unit);
        $this->assertTrue($type->is_active);
        $this->assertTrue(AuditLog::query()->where('action', 'health_safety.ppe_type_saved')->where('target_id', $type->id)->exists());

        $plain = $this->config()->saveType($this->officer0(), null, ['name' => 'Hard hat', 'category' => 'head', 'has_sizes' => false, 'sizes' => '38, 39', 'replacement_months' => '', 'unit' => '']);
        $this->assertNull($plain->sizes, 'a type without sizes keeps none');
        $this->assertNull($plain->replacement_months);
        $this->assertSame('each', $plain->unit);
    }

    public function test_a_type_needs_a_unique_name_a_category_and_sizes_when_it_has_them(): void
    {
        $officer = $this->officer0();
        $this->config()->saveType($officer, null, ['name' => 'Hard hat', 'category' => 'head']);

        foreach ([
            ['name' => 'hard hat', 'category' => 'head'],
            ['name' => '', 'category' => 'head'],
            ['name' => 'Gloves', 'category' => 'bogus'],
            ['name' => 'Gloves', 'category' => 'hand', 'has_sizes' => true, 'sizes' => ' , '],
            ['name' => 'Gloves', 'category' => 'hand', 'replacement_months' => '0'],
            ['name' => 'Gloves', 'category' => 'hand', 'replacement_months' => '999'],
        ] as $bad) {
            try {
                $this->config()->saveType($officer, null, $bad);
                $this->fail('Should be refused: '.json_encode($bad));
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }

        $this->assertSame(1, HsPpeType::query()->count());
    }

    public function test_sizes_cannot_be_turned_off_or_a_used_size_removed_once_a_type_is_in_use(): void
    {
        $officer = $this->officer0();
        $store = $this->ppeStore();
        $boots = $this->bootsType();
        $this->stocked($store, $boots, 3, '41');
        $same = ['name' => 'Safety boots', 'category' => 'foot', 'replacement_months' => 12];

        try {
            $this->config()->saveType($officer, $boots, [...$same, 'has_sizes' => false]);
            $this->fail('Not sized any more: stock is keyed by size.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('has_sizes', $exception->errors());
        }

        try {
            $this->config()->saveType($officer, $boots, [...$same, 'has_sizes' => true, 'sizes' => '40, 42']);
            $this->fail('Size 41 has stock.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('41', $exception->errors()['sizes'][0]);
        }

        // An unused size can go, a new one can be added, and the other settings can change.
        $changed = $this->config()->saveType($officer, $boots, [...$same, 'has_sizes' => true, 'sizes' => '41, 42, 43', 'replacement_months' => 18]);
        $this->assertSame(['41', '42', '43'], $changed->sizes);
        $this->assertSame(18, $changed->replacement_months);
    }

    public function test_a_type_can_be_deactivated_and_reactivated_but_never_deleted(): void
    {
        $officer = $this->officer0();
        $type = $this->ppeType(['name' => 'Hard hat']);

        Livewire::actingAs($officer)->test(PpeTypes::class)->call('toggleActive', $type->id);
        $this->assertFalse($type->fresh()->is_active);

        Livewire::actingAs($officer)->test(PpeTypes::class)->call('toggleActive', $type->id);
        $this->assertTrue($type->fresh()->is_active);
        $this->assertSame(1, HsPpeType::query()->count());
    }

    public function test_load_suggested_types_fills_an_editable_list_with_names_and_categories_only_and_saves_nothing(): void
    {
        $officer = $this->officer0();
        $this->ppeType(['name' => 'Safety helmet']);

        $component = Livewire::actingAs($officer)->test(PpeTypes::class)->call('loadSuggested');

        $suggested = $component->get('suggested');
        $this->assertNotEmpty($suggested);
        $this->assertNotContains('Safety helmet', collect($suggested)->pluck('name')->all(), 'a type that already exists is not suggested again');

        foreach ($suggested as $row) {
            $this->assertSame('', $row['sizes'], 'no sizes are suggested');
            $this->assertSame('', $row['replacement_months'], 'no service life is suggested');
            $this->assertArrayHasKey($row['category'], HsPpeType::CATEGORIES);
        }

        $this->assertContains(true, collect($suggested)->pluck('has_sizes')->all(), 'boots and gloves are marked as coming in sizes');
        $this->assertSame(1, HsPpeType::query()->count(), 'nothing was saved by loading the list');
        $component->assertSee('Confirm what you issue');
    }

    public function test_saving_the_suggested_list_needs_the_sized_rows_filled_in_and_saves_all_or_nothing(): void
    {
        $officer = $this->officer0();

        $component = Livewire::actingAs($officer)->test(PpeTypes::class)->call('loadSuggested');
        $component->call('saveSuggested')->assertHasErrors();   // the sized ones have no sizes yet

        $this->assertSame(0, HsPpeType::query()->count(), 'one bad row stops the lot');

        // Keep two rows: one unsized, one sized and completed.
        $rows = collect($component->get('suggested'));
        $keep = [
            ['name' => 'Safety helmet', 'category' => 'head', 'has_sizes' => false, 'sizes' => '', 'replacement_months' => '36'],
            ['name' => 'Safety boots', 'category' => 'foot', 'has_sizes' => true, 'sizes' => '39, 40, 41', 'replacement_months' => '12'],
        ];
        $this->assertTrue($rows->pluck('name')->contains('Safety helmet'));

        Livewire::actingAs($officer)->test(PpeTypes::class)->set('suggested', $keep)->call('saveSuggested')->assertHasNoErrors();

        $this->assertSame(['Safety boots', 'Safety helmet'], HsPpeType::query()->orderBy('name')->pluck('name')->all());
        $this->assertSame(['39', '40', '41'], HsPpeType::query()->where('name', 'Safety boots')->value('sizes'));
    }

    public function test_only_manage_master_data_can_change_types_entitlements_and_levels(): void
    {
        $manager = $this->districtManager('200003', $this->headOffice);   // view_equipment, no manage_master_data
        $store = $this->ppeStore();
        $type = $this->ppeType(['name' => 'Hard hat']);
        $title = JobTitle::query()->firstOrCreate(['job_title_name' => 'Technician']);

        foreach ([
            fn () => $this->config()->saveType($manager, null, ['name' => 'X', 'category' => 'head']),
            fn () => $this->config()->saveEntitlements($manager, $title, [$type->id => 1]),
            fn () => $this->config()->saveReorderLevels($manager, [['site_id' => $store->id, 'ppe_type_id' => $type->id, 'level' => 3]]),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('No manage_master_data.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }

        $this->actingAs($manager)->get(route('health_safety.ppe.types'))->assertForbidden();
        $this->actingAs($manager)->get(route('health_safety.ppe.entitlements'))->assertForbidden();
        $this->actingAs($manager)->get(route('health_safety.ppe.reorder-levels'))->assertForbidden();
        Livewire::actingAs($manager)->test(PpeTypes::class)->assertForbidden();
        Livewire::actingAs($manager)->test(PpeEntitlements::class)->assertForbidden();
        Livewire::actingAs($manager)->test(PpeReorderLevels::class)->assertForbidden();
    }

    // ---------------------------------------------------------------- entitlements

    public function test_entitlements_are_set_changed_and_cleared_per_job_title(): void
    {
        $officer = $this->officer0();
        $title = JobTitle::query()->firstOrCreate(['job_title_name' => 'Technician']);
        $boots = $this->bootsType();
        $hat = $this->ppeType(['name' => 'Hard hat']);

        $this->assertSame(2, $this->config()->saveEntitlements($officer, $title, [$boots->id => '1', $hat->id => 2]));
        $this->assertSame([1, 2], HsPpeEntitlement::query()->orderBy('ppe_type_id')->pluck('quantity')->all());
        $this->assertTrue(AuditLog::query()->where('action', 'health_safety.ppe_entitlement_saved')->exists());

        // Change one, clear the other (empty and 0 both mean none).
        $this->config()->saveEntitlements($officer, $title, [$boots->id => '3', $hat->id => '']);
        $this->assertSame(['3'], HsPpeEntitlement::query()->pluck('quantity')->map(fn ($q) => (string) $q)->all());
        $this->config()->saveEntitlements($officer, $title, [$boots->id => 0]);
        $this->assertSame(0, HsPpeEntitlement::query()->count());
    }

    public function test_an_entitlement_quantity_must_be_a_whole_number_of_at_least_one(): void
    {
        $officer = $this->officer0();
        $title = JobTitle::query()->firstOrCreate(['job_title_name' => 'Technician']);
        $hat = $this->ppeType(['name' => 'Hard hat']);

        foreach ([-1, 1.5, 'x', 5000] as $bad) {
            try {
                $this->config()->saveEntitlements($officer, $title, [$hat->id => $bad]);
                $this->fail("{$bad} must be refused");
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }

        $this->assertSame(0, HsPpeEntitlement::query()->count());
    }

    public function test_the_entitlements_screen_lists_the_job_titles_with_staff_here_and_saves_through_the_form(): void
    {
        $officer = $this->officer0();
        $hat = $this->ppeType(['name' => 'Hard hat']);
        $technician = JobTitle::query()->firstOrCreate(['job_title_name' => 'Technician']);
        JobTitle::query()->firstOrCreate(['job_title_name' => 'Unused Title Elsewhere']);
        $staff = $this->userWithRoles('300001', ['employee'], $this->accraWest, $this->sowutuom);
        $staff->employee->update(['job_title_id' => $technician->id]);

        $component = Livewire::actingAs($officer)->test(PpeEntitlements::class)
            ->assertSee('Technician')
            ->assertDontSee('Unused Title Elsewhere')
            ->call('edit', $technician->id)
            ->set("quantities.{$hat->id}", '2')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(2, (int) HsPpeEntitlement::query()->where('job_title_id', $technician->id)->value('quantity'));
        $component->assertSee('Technician');
    }

    // ---------------------------------------------------------------- reorder levels

    public function test_reorder_levels_are_saved_per_store_and_type_and_only_for_stores_in_scope(): void
    {
        $officer = $this->officer('200001', $this->accraWest, $this->odorkor);
        $mine = $this->ppeStore('Accra Store', $this->odorkor);
        $theirs = $this->ppeStore('Kumasi Store', $this->kumasi);
        $hat = $this->ppeType(['name' => 'Hard hat']);

        $this->assertSame(1, $this->config()->saveReorderLevels($officer, [['site_id' => $mine->id, 'ppe_type_id' => $hat->id, 'level' => '10']]));
        $this->assertSame(10, HsPpeReorderLevel::query()->value('level'));
        $this->assertTrue(AuditLog::query()->where('action', 'health_safety.ppe_reorder_level_saved')->exists());

        $this->assertSame(0, $this->config()->saveReorderLevels($officer, [['site_id' => $mine->id, 'ppe_type_id' => $hat->id, 'level' => 10]]), 'saving the same level changes nothing');

        try {
            $this->config()->saveReorderLevels($officer, [['site_id' => $theirs->id, 'ppe_type_id' => $hat->id, 'level' => 5]]);
            $this->fail('Another region.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        foreach (['-1', 'x', 1.5] as $bad) {
            try {
                $this->config()->saveReorderLevels($officer, [['site_id' => $mine->id, 'ppe_type_id' => $hat->id, 'level' => $bad]]);
                $this->fail("{$bad} must be refused");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('level', $exception->errors());
            }
        }

        $this->assertSame(10, HsPpeReorderLevel::query()->value('level'));
    }

    public function test_a_reorder_level_cannot_be_set_on_a_site_that_is_not_a_store(): void
    {
        $this->expectException(ValidationException::class);

        $this->config()->saveReorderLevels($this->officer0(), [['site_id' => $this->site('Plain office')->id, 'ppe_type_id' => $this->ppeType()->id, 'level' => 3]]);
    }

    public function test_the_reorder_levels_screen_saves_what_is_typed(): void
    {
        $officer = $this->officer0();
        $store = $this->ppeStore();
        $hat = $this->ppeType(['name' => 'Hard hat']);
        $boots = $this->bootsType();

        Livewire::actingAs($officer)->test(PpeReorderLevels::class)
            ->set('storeId', (string) $store->id)
            ->set("levels.{$hat->id}", '12')
            ->set("levels.{$boots->id}", '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame([$hat->id => 12], HsPpeReorderLevel::query()->pluck('level', 'ppe_type_id')->all());
    }

    // ---------------------------------------------------------------- the store flag on Sites

    public function test_a_site_is_marked_as_a_ppe_store_on_the_sites_screen(): void
    {
        $officer = $this->officer0();

        Livewire::actingAs($officer)->test(Sites::class)
            ->call('create')
            ->set(['name' => 'Central Store', 'kind' => 'depot', 'isPpeStore' => true])
            ->call('save')
            ->assertHasNoErrors();

        $site = \App\Models\HsSite::query()->where('name', 'Central Store')->firstOrFail();
        $this->assertTrue($site->is_ppe_store);
        $this->assertSame(1, \App\Models\HsSite::query()->ppeStores()->count());
        Livewire::actingAs($officer)->test(Sites::class)->assertSee('PPE store');
    }

    public function test_the_sites_screen_notes_that_a_store_is_normally_the_regional_office(): void
    {
        $component = Livewire::actingAs($this->officer0())->test(Sites::class)->call('create')->set(['kind' => 'depot', 'isPpeStore' => true]);
        $component->assertSee('PPE stores are normally the regional office.');

        $component->set('kind', 'regional_office')->assertDontSee('PPE stores are normally the regional office.');
    }

    public function test_a_store_holding_stock_cannot_stop_being_a_store_or_be_deactivated(): void
    {
        $officer = $this->officer0();
        $store = $this->ppeStore();
        $this->stocked($store, $this->ppeType(['name' => 'Hard hat']), 4);

        Livewire::actingAs($officer)->test(Sites::class)
            ->call('edit', $store->id)
            ->set('isPpeStore', false)
            ->call('save')
            ->assertHasErrors(['isPpeStore']);

        Livewire::actingAs($officer)->test(Sites::class)->call('toggleActive', $store->id)->assertHasErrors(['isPpeStore']);

        $this->assertTrue($store->fresh()->is_ppe_store);
        $this->assertTrue($store->fresh()->is_active);

        // Once it is empty, both are fine.
        app(\App\Services\HealthSafety\PpeStockService::class)->writeOff($officer, $store, HsPpeType::query()->first(), null, 4, 'Cleared out');
        Livewire::actingAs($officer)->test(Sites::class)->call('edit', $store->id)->set('isPpeStore', false)->call('save')->assertHasNoErrors();
        $this->assertFalse($store->fresh()->is_ppe_store);
    }
}
