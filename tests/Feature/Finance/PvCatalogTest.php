<?php

namespace Tests\Feature\Finance;

use App\Livewire\Finance\Settings;
use App\Livewire\Finance\VoucherForm;
use App\Models\AuditLog;
use App\Models\FinPvCategory;
use App\Models\FinPvType;
use App\Services\Finance\PvCatalogService;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** The category / type catalogue: shared company-wide, changed only through PvCatalogService, never deleted. */
class PvCatalogTest extends FinanceTestCase
{
    public function test_the_catalogue_is_seeded_with_the_two_categories_and_their_types(): void
    {
        $staff = FinPvCategory::query()->where('name', 'Staff Claims')->firstOrFail();
        $operations = FinPvCategory::query()->where('name', 'Operations Activities')->firstOrFail();

        $this->assertSame(
            ['Medical', 'Risk Allowance', 'Travel and Transportation (T&T) Allowance', 'Sitting Allowance', 'Incidental Allowance'],
            $staff->types()->orderBy('sort_order')->pluck('name')->all()
        );
        $this->assertSame(['Operations', 'Stationery', 'New Service', 'Contracts'], $operations->types()->orderBy('sort_order')->pluck('name')->all());
    }

    public function test_create_rename_and_duplicate_rules(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $this->actingAs($fin);
        $catalogue = app(PvCatalogService::class);

        $category = $catalogue->createCategory($fin, '  Capital   Works ');
        $this->assertSame('Capital Works', $category->name);

        $this->assertValidation(fn () => $catalogue->createCategory($fin, 'capital works'));
        $this->assertValidation(fn () => $catalogue->createCategory($fin, ' '));
        $this->assertValidation(fn () => $catalogue->renameCategory($fin, $category->id, 'staff claims'));

        $type = $catalogue->createType($fin, $category->id, 'Roads');
        $this->assertValidation(fn () => $catalogue->createType($fin, $category->id, 'ROADS'));
        // The same type name under another category is fine.
        $this->assertSame('Roads', $catalogue->createType($fin, FinPvCategory::query()->where('name', 'Staff Claims')->value('id'), 'Roads')->name);

        $this->assertSame('Bridges', $catalogue->renameType($fin, $type->id, 'Bridges')->name);
    }

    public function test_it_needs_the_permission_and_audit_is_read_only(): void
    {
        $aud = $this->audit('AU1', $this->sowutuom);
        $catalogue = app(PvCatalogService::class);

        try {
            $catalogue->createCategory($aud, 'Not allowed');
            $this->fail('An audit officer must not change the catalogue.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->actingAs($aud);
        Livewire::test(Settings::class, ['module' => 'audit'])
            ->assertSee('Staff Claims')
            ->assertSee('You can read the list')
            ->assertDontSee('Add a category')
            ->set('newCategory', 'Sneaky')
            ->call('addCategory')
            ->assertForbidden();

        $this->assertSame(0, FinPvCategory::query()->where('name', 'Sneaky')->count());
    }

    public function test_the_catalogue_is_shared_across_locations(): void
    {
        $west = $this->finance('W1', $this->sowutuom);
        $ash = $this->finance('A1', $this->kumasi);
        $this->actingAs($west);

        $category = app(PvCatalogService::class)->createCategory($west, 'Shared Category');

        $this->actingAs($ash);
        Livewire::test(Settings::class)->assertSee('Shared Category');
        $this->assertSame($category->id, FinPvCategory::query()->where('name', 'Shared Category')->value('id'));
    }

    public function test_a_deactivated_type_cannot_start_a_new_voucher_but_stays_on_the_old_one_and_in_its_form(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $this->actingAs($fin);
        $pv = $this->draft($fin);
        $medical = FinPvType::query()->where('name', 'Medical')->firstOrFail();

        app(PvCatalogService::class)->setTypeActive($fin, $medical->id, false);

        // New vouchers cannot use it...
        $this->assertValidation(fn () => $this->workflow()->createDraft($fin, $this->pvData(['pv_no' => 'NEW-1'])));

        // ...but the old one still saves, and its form keeps the type selectable.
        $this->assertSame($medical->id, $this->workflow()->update($fin, $pv, $this->pvData(['amount' => '5.00']), $pv->lock_version)->type_id);
        Livewire::test(VoucherForm::class, ['pv' => $pv->id])->assertSee('Medical')->assertSee('no longer in use');

        app(PvCatalogService::class)->setCategoryActive($fin, $medical->category_id, false);
        $this->assertSame($medical->id, $this->workflow()->update($fin, $pv->fresh(), $this->pvData(['amount' => '6.00']), $pv->fresh()->lock_version)->type_id);
    }

    public function test_a_type_in_use_cannot_move_but_an_unused_one_can_and_nothing_is_deleted(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $this->actingAs($fin);
        $this->draft($fin);
        $catalogue = app(PvCatalogService::class);
        $operations = FinPvCategory::query()->where('name', 'Operations Activities')->firstOrFail();
        $medical = FinPvType::query()->where('name', 'Medical')->firstOrFail();
        $stationery = FinPvType::query()->where('name', 'Stationery')->firstOrFail();

        $this->assertValidation(fn () => $catalogue->moveType($fin, $medical->id, $operations->id));

        $moved = $catalogue->moveType($fin, $stationery->id, FinPvCategory::query()->where('name', 'Staff Claims')->value('id'));
        $this->assertSame('Staff Claims', $moved->category->name);

        $before = FinPvType::query()->count();
        $catalogue->setTypeActive($fin, $stationery->id, false);
        $this->assertSame($before, FinPvType::query()->count());
    }

    public function test_every_change_is_audited_with_ids_only(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $this->actingAs($fin);
        $catalogue = app(PvCatalogService::class);

        $category = $catalogue->createCategory($fin, 'Confidential Programme');
        $catalogue->renameCategory($fin, $category->id, 'Renamed Programme');
        $type = $catalogue->createType($fin, $category->id, 'Hidden Type');
        $catalogue->setTypeActive($fin, $type->id, false);

        $rows = AuditLog::query()->orderBy('id')->get();
        $this->assertSame(['finance.category_created', 'finance.category_renamed', 'finance.type_created', 'finance.type_deactivated'], $rows->pluck('action')->all());

        $json = json_encode($rows->toArray());
        foreach (['Confidential', 'Renamed', 'Hidden'] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }
    }

    public function test_the_form_panel_adds_a_category_and_type_for_the_holder_of_the_permission(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $this->actingAs($fin);

        Livewire::test(VoucherForm::class)
            ->set('newCategory', 'Fuel Cards')
            ->call('addCategory')
            ->assertHasNoErrors()
            ->set('newType', 'Diesel')
            ->call('addType')
            ->assertHasNoErrors();

        $type = FinPvType::query()->where('name', 'Diesel')->firstOrFail();
        $this->assertSame('Fuel Cards', $type->category->name);
    }

    private function assertValidation(callable $call): void
    {
        try {
            $call();
            $this->fail('Expected a validation error.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }
}
