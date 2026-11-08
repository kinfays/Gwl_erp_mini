<?php

namespace Tests\Feature\Finance;

use App\Livewire\Finance\Settings;
use App\Livewire\Finance\VoucherForm;
use App\Models\AuditLog;
use App\Models\FinCatalogOverride;
use App\Models\FinPvCategory;
use App\Models\FinPvType;
use App\Services\Finance\FinanceBooksVersion;
use App\Services\Finance\FinanceScope;
use App\Services\Finance\PvAnalyticsService;
use App\Services\Finance\PvCatalogService;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Step B: the catalogue is per location, "own row else system default": standard rows, location overrides and location-owned rows. */
class PvCatalogPerLocationTest extends FinanceTestCase
{
    private function catalogue(): PvCatalogService
    {
        return app(PvCatalogService::class);
    }

    private function place($district): array
    {
        return ['region_id' => $district->region_id, 'district_id' => $district->id];
    }

    private function medical(): FinPvType
    {
        return FinPvType::query()->where('name', 'Medical')->whereNull('unit')->firstOrFail();
    }

    /** The effective type names of a category at a location. */
    private function typeNames($district, string $category = 'Staff Claims', bool $activeOnly = false): array
    {
        $found = $this->catalogue()->effective($this->place($district))->first(fn ($c) => $c->effective_name === $category || $c->name === $category);

        return $found->types->filter(fn ($t) => ! $activeOnly || $t->effective_active)->pluck('effective_name')->all();
    }

    private function assertValidation(callable $call, ?string $key = null): void
    {
        try {
            $call();
            $this->fail('Expected a validation error.');
        } catch (ValidationException $exception) {
            $key === null ? $this->assertTrue(true) : $this->assertArrayHasKey($key, $exception->errors());
        }
    }

    private function assertStatus(int $status, callable $call): void
    {
        try {
            $call();
            $this->fail("Expected a {$status}.");
        } catch (HttpException $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }

    // ------------------------------------------------------------------ defaults, overrides, owned rows

    public function test_the_seeded_rows_are_system_defaults_and_every_location_sees_them(): void
    {
        $this->assertSame(0, FinPvCategory::query()->whereNotNull('unit')->count());
        $this->assertSame(0, FinPvType::query()->whereNotNull('unit')->count());

        foreach ([$this->sowutuom, $this->kumasi, $this->headOffice] as $district) {
            $this->assertSame(['Medical', 'Risk Allowance', 'Travel and Transportation (T&T) Allowance', 'Sitting Allowance', 'Incidental Allowance'], $this->typeNames($district));
        }

        $this->assertSame(['Staff Claims', 'Operations Activities'], $this->catalogue()->effective(null)->pluck('effective_name')->all());
    }

    public function test_a_rename_is_an_override_for_the_managers_location_only(): void
    {
        $west = $this->financeManager('W1', $this->sowutuom);
        $medical = $this->medical();

        $this->actingAs($west);
        $this->catalogue()->renameType($west, $medical->id, 'Medical Claims');

        $this->assertContains('Medical Claims', $this->typeNames($this->sowutuom));
        $this->assertContains('Medical', $this->typeNames($this->kumasi), 'another location is unchanged');
        $this->assertContains('Medical', $this->typeNames($this->headOffice), 'Head Office keeps its own books (it shares the region id)');
        $this->assertSame('Medical', $medical->fresh()->name, 'the standard row is untouched');

        $row = FinCatalogOverride::query()->firstOrFail();
        $this->assertSame(['r'.$this->sowutuom->region_id, 'type', $medical->id, 'Medical Claims', null], [$row->unit, $row->kind, $row->item_id, $row->display_name, $row->is_active]);

        // Another district of the same region is the same books.
        $this->assertContains('Medical Claims', $this->typeNames($this->odorkor));

        // Renaming back to the standard name removes the override: an override exists only while it differs.
        $this->catalogue()->renameType($west, $medical->id, 'Medical');
        $this->assertSame(0, FinCatalogOverride::query()->count());
    }

    public function test_a_location_owned_category_and_type_belong_to_that_location_only_and_names_may_repeat_elsewhere(): void
    {
        $west = $this->financeManager('W1', $this->sowutuom);
        $ash = $this->financeManager('A1', $this->kumasi);
        $this->actingAs($west);

        $category = $this->catalogue()->createCategory($west, 'Capital Works');
        $type = $this->catalogue()->createType($west, $category->id, 'Roads');
        $inStandard = $this->catalogue()->createType($west, FinPvCategory::query()->where('name', 'Operations Activities')->value('id'), 'Fuel');

        $this->assertSame('r'.$this->sowutuom->region_id, $category->unit);
        $this->assertSame($category->unit, $type->unit);
        $this->assertContains('Capital Works', $this->catalogue()->effective($this->place($this->sowutuom))->pluck('effective_name')->all());
        $this->assertNotContains('Capital Works', $this->catalogue()->effective($this->place($this->kumasi))->pluck('effective_name')->all());
        $this->assertContains('Fuel', $this->typeNames($this->sowutuom, 'Operations Activities'));
        $this->assertNotContains('Fuel', $this->typeNames($this->kumasi, 'Operations Activities'));

        // The other location may use the same names for its own rows.
        $this->actingAs($ash);
        $this->assertNotNull($this->catalogue()->createCategory($ash, 'Capital Works'));
        $this->assertNotNull($this->catalogue()->createType($ash, FinPvCategory::query()->where('name', 'Operations Activities')->value('id'), 'Fuel'));

        // ...and cannot reach this location's rows by id: 404, as for anything outside the location.
        $this->assertStatus(404, fn () => $this->catalogue()->renameCategory($ash, $category->id, 'Mine now'));
        $this->assertStatus(404, fn () => $this->catalogue()->setTypeActive($ash, $inStandard->id, false));
        $this->assertStatus(404, fn () => $this->catalogue()->moveType($ash, $type->id, FinPvCategory::query()->where('name', 'Staff Claims')->value('id')));
    }

    public function test_names_are_unique_case_insensitively_within_the_effective_list(): void
    {
        $west = $this->financeManager('W1', $this->sowutuom);
        $this->actingAs($west);
        $staff = FinPvCategory::query()->where('name', 'Staff Claims')->firstOrFail();

        $this->assertValidation(fn () => $this->catalogue()->createCategory($west, 'staff claims'), 'name');
        $this->assertValidation(fn () => $this->catalogue()->createType($west, $staff->id, 'MEDICAL'), 'name');

        // The effective name counts, not the standard one: after a rename, the old name is free and the new one is taken.
        $this->catalogue()->renameType($west, $this->medical()->id, 'Medical Claims');
        $this->assertNotNull($this->catalogue()->createType($west, $staff->id, 'Medical'));
        $this->assertValidation(fn () => $this->catalogue()->createType($west, $staff->id, 'medical claims'), 'name');
        $this->assertValidation(fn () => $this->catalogue()->renameType($west, FinPvType::query()->where('name', 'Risk Allowance')->value('id'), 'MEDICAL CLAIMS'), 'name');

        // A rename may differ only in case from its own old name.
        $this->assertNotNull($this->catalogue()->renameType($west, $this->medical()->id, 'medical CLAIMS'));
        $this->assertValidation(fn () => $this->catalogue()->createCategory($west, ' '), 'name');
        $this->assertValidation(fn () => $this->catalogue()->createCategory($west, str_repeat('x', 121)), 'name');
    }

    public function test_switching_off_is_per_location_a_category_takes_its_types_with_it_and_nothing_is_deleted(): void
    {
        $west = $this->financeManager('W1', $this->sowutuom);
        $staff = FinPvCategory::query()->where('name', 'Staff Claims')->firstOrFail();
        $risk = FinPvType::query()->where('name', 'Risk Allowance')->firstOrFail();
        $this->actingAs($west);
        $countBefore = FinPvType::query()->count() + FinPvCategory::query()->count();

        $this->catalogue()->setTypeActive($west, $risk->id, false);
        $this->assertNotContains('Risk Allowance', $this->typeNames($this->sowutuom, activeOnly: true));
        $this->assertContains('Risk Allowance', $this->typeNames($this->kumasi, activeOnly: true));

        $this->catalogue()->setCategoryActive($west, $staff->id, false);
        $this->assertSame([], $this->typeNames($this->sowutuom, activeOnly: true), 'every type of the category is off here');
        $this->assertNotSame([], $this->typeNames($this->kumasi, activeOnly: true));

        // Switching the category back on restores its types, except the one switched off on its own.
        $this->catalogue()->setCategoryActive($west, $staff->id, true);
        $active = $this->typeNames($this->sowutuom, activeOnly: true);
        $this->assertContains('Medical', $active);
        $this->assertNotContains('Risk Allowance', $active);

        $this->catalogue()->setTypeActive($west, $risk->id, true);
        $this->assertSame(0, FinCatalogOverride::query()->count(), 'back at the default: no row');
        $this->assertSame($countBefore, FinPvType::query()->count() + FinPvCategory::query()->count());
        $this->assertTrue($risk->fresh()->is_active, 'the standard row was never switched off');
    }

    // ------------------------------------------------------------------ vouchers

    public function test_a_voucher_form_offers_only_the_locations_active_items_and_a_voucher_keeps_its_own(): void
    {
        $officer = $this->finance('F1', $this->sowutuom);
        $manager = $this->financeManager('FM1', $this->sowutuom);
        $staff = FinPvCategory::query()->where('name', 'Staff Claims')->firstOrFail();
        $pv = $this->draft($officer);

        $this->actingAs($manager);
        $this->catalogue()->renameType($manager, $this->medical()->id, 'Medical Claims');
        $this->catalogue()->setTypeActive($manager, FinPvType::query()->where('name', 'Risk Allowance')->value('id'), false);
        $own = $this->catalogue()->createType($manager, $staff->id, 'Welfare');

        $new = Livewire::actingAs($officer)->test(VoucherForm::class)->set('form.category_id', (string) $staff->id);
        $new->assertSee('Medical Claims')->assertSee('Welfare')->assertDontSee('Risk Allowance');

        // Another location's form is the standard list, without this location's addition.
        Livewire::actingAs($this->finance('A1', $this->kumasi))->test(VoucherForm::class)->set('form.category_id', (string) $staff->id)
            ->assertSee('Risk Allowance')->assertDontSee('Welfare')->assertDontSee('Medical Claims');

        // The voucher on the screen carries the location's CURRENT name; switching its type off does not hide it from its own form.
        $this->catalogue()->setTypeActive($manager, $this->medical()->id, false);
        Livewire::actingAs($officer)->test(VoucherForm::class, ['pv' => $pv->id])->assertSee('Medical Claims')->assertSee('no longer in use');
        $this->assertSame($this->medical()->id, $this->workflow()->update($officer, $pv, $this->pvData(['amount' => '9.00']), $pv->lock_version)->type_id);

        // But nobody can START a voucher with it, with a type that is off, or with another location's own type.
        $this->assertValidation(fn () => $this->workflow()->createDraft($officer, $this->pvData(['pv_no' => 'NEW-1'])), 'type_id');
        $this->assertValidation(fn () => $this->workflow()->createDraft($officer, $this->pvData(['pv_no' => 'NEW-2', 'type_id' => FinPvType::query()->where('name', 'Risk Allowance')->value('id')])), 'type_id');
        $this->assertNotNull($this->workflow()->createDraft($officer, $this->pvData(['pv_no' => 'NEW-3', 'type_id' => $own->id])));

        $ash = $this->finance('A2', $this->kumasi);
        $this->actingAs($ash);
        $this->assertValidation(fn () => $this->workflow()->createDraft($ash, $this->pvData(['pv_no' => 'NEW-4', 'type_id' => $own->id, 'category_id' => $staff->id])), 'type_id');
    }

    public function test_a_vouchers_labels_use_the_name_of_its_own_location(): void
    {
        $west = $this->finance('W1', $this->sowutuom);
        $ash = $this->finance('A1', $this->kumasi);
        $westPv = $this->sent($west, ['pv_no' => 'W-1']);
        $ashPv = $this->sent($ash, ['pv_no' => 'A-1']);
        $manager = $this->financeManager('FM1', $this->sowutuom);
        $this->actingAs($manager);
        $this->catalogue()->renameType($manager, $this->medical()->id, 'Medical Claims');
        $this->catalogue()->renameCategory($manager, FinPvCategory::query()->where('name', 'Staff Claims')->value('id'), 'Staff Benefits');

        $this->assertSame(['category' => 'Staff Benefits', 'type' => 'Medical Claims'], $this->catalogue()->labelsOf($westPv));
        $this->assertSame(['category' => 'Staff Claims', 'type' => 'Medical'], $this->catalogue()->labelsOf($ashPv));

        $both = collect([$westPv->fresh(), $ashPv->fresh()]);
        $this->catalogue()->labelPvs($both);
        $this->assertSame(['Medical Claims', 'Medical'], $both->pluck('type_label')->all());

        // A change that Audit sees as "what changed since the last query" uses the same names.
        $this->assertSame('Medical Claims', $westPv->fresh()->contentSnapshot()['Type']);
    }

    // ------------------------------------------------------------------ who may change what

    public function test_the_standard_list_is_changed_only_by_super_admin_and_everyone_sees_it_at_once(): void
    {
        $west = $this->financeManager('W1', $this->sowutuom);
        $super = $this->superAdmin();
        $this->actingAs($west);

        $this->assertStatus(403, fn () => $this->catalogue()->renameType($west, $this->medical()->id, 'Hijacked', null, true));
        $this->assertStatus(403, fn () => $this->catalogue()->createCategory($west, 'Hijacked', null, true));

        // super_admin must say where the change is going: a location or the standard list.
        $this->actingAs($super);
        $medicalId = $this->medical()->id;
        $this->assertValidation(fn () => $this->catalogue()->renameType($super, $medicalId, 'No place'), 'location');

        $versionBefore = FinanceBooksVersion::current($this->place($this->kumasi));
        $this->catalogue()->renameType($super, $medicalId, 'Medical Treatment', null, true);

        $this->assertSame('Medical Treatment', FinPvType::query()->findOrFail($medicalId)->name);
        $this->assertContains('Medical Treatment', $this->typeNames($this->kumasi));
        $this->assertGreaterThan($versionBefore, FinanceBooksVersion::current($this->place($this->kumasi)), 'every location\'s figures are dropped');

        // super_admin can also act for one location by naming its district: that is an override, as it is for the manager.
        $this->catalogue()->renameType($super, $medicalId, 'Medical Western', $this->sowutuom->id);
        $this->assertContains('Medical Western', $this->typeNames($this->sowutuom));
        $this->assertContains('Medical Treatment', $this->typeNames($this->kumasi));

        // A location-owned row is not part of the standard list.
        $owned = $this->catalogue()->createType($super, FinPvCategory::query()->where('name', 'Staff Claims')->value('id'), 'Western Only', $this->sowutuom->id);
        $this->assertStatus(404, fn () => $this->catalogue()->renameType($super, $owned->id, 'Nope', null, true));
    }

    public function test_every_change_is_audited_with_ids_only_and_the_location_key(): void
    {
        $west = $this->financeManager('W1', $this->sowutuom);
        $this->actingAs($west);

        $category = $this->catalogue()->createCategory($west, 'Confidential Programme');
        $this->catalogue()->renameType($west, $this->medical()->id, 'Secret Medical');
        $this->catalogue()->setTypeActive($west, $this->medical()->id, false);
        $this->catalogue()->createType($west, $category->id, 'Hidden Type');

        $rows = AuditLog::query()->where('module', 'finance')->orderBy('id')->get();
        $this->assertSame(['finance.category_created', 'finance.type_renamed', 'finance.type_deactivated', 'finance.type_created'], $rows->pluck('action')->all());
        $this->assertSame('r'.$this->sowutuom->region_id, $rows[1]->metadata['unit']);
        $this->assertTrue($rows[1]->metadata['override']);

        $json = json_encode($rows->toArray());
        foreach (['Confidential', 'Secret', 'Hidden'] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }
    }

    // ------------------------------------------------------------------ analytics

    public function test_reports_show_the_locations_names_and_the_comparison_label_groups_by_the_standard_item(): void
    {
        $west = $this->finance('W1', $this->sowutuom);
        $ash = $this->finance('A1', $this->kumasi);
        $this->sent($west, ['pv_no' => 'W-1']);
        $this->sent($ash, ['pv_no' => 'A-1']);
        $manager = $this->financeManager('FM1', $this->sowutuom);
        $this->actingAs($manager);
        $this->catalogue()->renameType($manager, $this->medical()->id, 'Medical Claims');
        $own = $this->catalogue()->createCategory($manager, 'Capital Works');

        $analytics = app(PvAnalyticsService::class);
        $westTypes = json_encode($analytics->report($west, FinanceScope::AS_FINANCE, $this->place($this->sowutuom), '2026-10-01', '2026-11-30')['types']);
        $ashTypes = json_encode($analytics->report($ash, FinanceScope::AS_FINANCE, $this->place($this->kumasi), '2026-10-01', '2026-11-30')['types']);
        $this->assertStringContainsString('Staff Claims > Medical Claims', $westTypes);
        $this->assertStringContainsString('Staff Claims > Medical"', $ashTypes);

        // For a view that sets locations side by side: the standard item under its standard name, an owned one with its place.
        $this->assertSame('Medical', $this->catalogue()->comparisonLabel('type', $this->medical()->id));
        $this->assertSame('Accra West: Capital Works', $this->catalogue()->comparisonLabel('category', $own->id), 'the test region is named Accra West');
    }

    // ------------------------------------------------------------------ the Settings screen

    public function test_the_manager_works_through_the_settings_screen_for_their_own_location(): void
    {
        $this->withoutVite();
        $west = $this->financeManager('W1', $this->sowutuom);
        $staff = FinPvCategory::query()->where('name', 'Staff Claims')->firstOrFail();
        $operations = FinPvCategory::query()->where('name', 'Operations Activities')->firstOrFail();

        $page = Livewire::actingAs($west)->test(Settings::class)
            ->assertSee('Staff Claims')->assertSee('Add a category')
            ->set('newCategory', 'Capital Works')->call('addCategory')->assertHasNoErrors()->assertSee('Added for this location')
            ->set('newType.'.$staff->id, 'Welfare')->call('addType', $staff->id)->assertHasNoErrors()->assertSee('Welfare')
            ->call('startRenameCategory', $staff->id)->set('renameValue', 'Staff Benefits')->call('saveRename')->assertHasNoErrors()
            ->assertSee('Staff Benefits')->assertSee('Renamed here (standard name: Staff Claims)')
            ->call('toggleType', FinPvType::query()->where('name', 'Contracts')->value('id'), false)->assertSee('Not in use');

        $welfare = FinPvType::query()->where('name', 'Welfare')->firstOrFail();
        $page->set('moveTo.'.$welfare->id, (string) $operations->id)->call('moveType', $welfare->id)->assertHasNoErrors();
        $this->assertSame($operations->id, $welfare->fresh()->category_id);

        $page->set('newCategory', 'capital works')->call('addCategory')->assertHasErrors(['catalogue.name']);

        // A manager forcing the "system defaults" switch is refused by the service.
        $page->set('defaults', true)->set('newCategory', 'Nope')->call('addCategory')->assertForbidden();
    }

    public function test_super_admin_chooses_a_location_or_the_standard_list_on_the_settings_screen(): void
    {
        $this->withoutVite();
        $super = $this->superAdmin();

        $page = Livewire::actingAs($super)->test(Settings::class)->assertSee('Choose a location');
        $page->set('newCategory', 'Too early')->call('addCategory')->assertHasErrors(['catalogue.location']);

        $page->set('locationId', (string) $this->kumasi->id)->assertSee('Staff Claims')
            ->set('newCategory', 'Ashanti Only')->call('addCategory')->assertHasNoErrors();
        $this->assertSame('r'.$this->kumasi->region_id, FinPvCategory::query()->where('name', 'Ashanti Only')->value('unit'));

        $page->set('defaults', true)->assertDontSee('Ashanti Only')
            ->set('newCategory', 'Everywhere')->call('addCategory')->assertHasNoErrors();
        $this->assertNull(FinPvCategory::query()->where('name', 'Everywhere')->value('unit'));
    }
}
