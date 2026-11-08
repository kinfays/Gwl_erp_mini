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
        $fin = $this->financeManager('F1', $this->sowutuom);
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

    public function test_only_the_finance_manager_changes_the_catalogue_and_audit_has_no_door_to_it(): void
    {
        $catalogue = app(PvCatalogService::class);

        // The officer (who prepares vouchers), both Audit roles and a person with no role at all are refused by the service itself.
        foreach ([$this->finance('F1', $this->sowutuom), $this->audit('AU1', $this->sowutuom), $this->auditManager('AM1', $this->sowutuom), $this->person('E1', $this->sowutuom)] as $who) {
            try {
                $catalogue->createCategory($who, 'Not allowed');
                $this->fail('Only a holder of finance.manage_categories may change the catalogue.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }

        // The screen, its route and its menu entry are the manager's alone: the officer gets 403 and no link.
        $this->withoutVite();
        $officer = $this->finance('F2', $this->sowutuom);
        $this->actingAs($officer)->get(route('finance.settings'))->assertForbidden();
        Livewire::actingAs($officer)->test(Settings::class)->assertForbidden();
        $this->assertNotContains('finance.settings', collect(app(\App\Support\ErpNavigation::class)->build($officer, 'finance')['sidebar'])->pluck('route')->all());
        $this->assertContains('finance.settings', collect(app(\App\Support\ErpNavigation::class)->build($this->financeManager('FM9', $this->sowutuom), 'finance')['sidebar'])->pluck('route')->all());

        // Audit has no category screen or menu entry whatever its role: the route answers 403.
        $this->withoutVite();
        $this->actingAs($this->audit('AU2', $this->sowutuom))->get(route('audit.categories'))->assertForbidden();
        $this->actingAs($this->auditManager('AM2', $this->sowutuom))->get(route('audit.categories'))->assertForbidden();
        $this->actingAs($this->superAdmin())->get(route('audit.categories'))->assertForbidden();
        $this->assertNotContains('audit.categories', collect(app(\App\Support\ErpNavigation::class)->build($this->audit('AU3', $this->sowutuom), 'audit')['sidebar'])->pluck('route')->all());
    }

    public function test_a_deactivated_type_cannot_start_a_new_voucher_but_stays_on_the_old_one_and_in_its_form(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $manager = $this->financeManager('M1', $this->sowutuom);
        $this->actingAs($fin);
        $pv = $this->draft($fin);
        $medical = FinPvType::query()->where('name', 'Medical')->firstOrFail();

        app(PvCatalogService::class)->setTypeActive($manager, $medical->id, false);

        // New vouchers cannot use it...
        $this->assertValidation(fn () => $this->workflow()->createDraft($fin, $this->pvData(['pv_no' => 'NEW-1'])));

        // ...but the old one still saves, and its form keeps the type selectable.
        $this->assertSame($medical->id, $this->workflow()->update($fin, $pv, $this->pvData(['amount' => '5.00']), $pv->lock_version)->type_id);
        Livewire::test(VoucherForm::class, ['pv' => $pv->id])->assertSee('Medical')->assertSee('no longer in use');

        app(PvCatalogService::class)->setCategoryActive($manager, $medical->category_id, false);
        $this->assertSame($medical->id, $this->workflow()->update($fin, $pv->fresh(), $this->pvData(['amount' => '6.00']), $pv->fresh()->lock_version)->type_id);
    }

    public function test_a_type_in_use_cannot_move_but_an_unused_one_can_and_nothing_is_deleted(): void
    {
        $officer = $this->finance('F1', $this->sowutuom);
        $manager = $this->financeManager('M1', $this->sowutuom);
        $this->actingAs($manager);
        $catalogue = app(PvCatalogService::class);
        $staff = FinPvCategory::query()->where('name', 'Staff Claims')->firstOrFail();
        $operations = FinPvCategory::query()->where('name', 'Operations Activities')->firstOrFail();

        $used = $catalogue->createType($manager, $operations->id, 'Fuel');
        $spare = $catalogue->createType($manager, $operations->id, 'Printing');
        $this->actingAs($officer);
        $this->workflow()->createDraft($officer, $this->pvData(['category_id' => $operations->id, 'type_id' => $used->id]));
        $this->actingAs($manager);

        // A type vouchers use cannot move; an unused one of the location's own can; a standard one is not the location's to move.
        $this->assertValidation(fn () => $catalogue->moveType($manager, $used->id, $staff->id));
        $this->assertSame($staff->id, $catalogue->moveType($manager, $spare->id, $staff->id)->category_id);
        $stationery = FinPvType::query()->where('name', 'Stationery')->firstOrFail();
        $this->assertValidation(fn () => $catalogue->moveType($manager, $stationery->id, $staff->id));

        // super_admin changing the standard list can move a standard type nobody has used.
        $this->assertSame($staff->id, $catalogue->moveType($this->superAdmin(), $stationery->id, $staff->id, null, true)->category_id);

        $before = FinPvType::query()->count();
        $catalogue->setTypeActive($manager, $stationery->id, false);
        $this->assertSame($before, FinPvType::query()->count(), 'nothing is ever deleted');
    }

    public function test_every_change_is_audited_with_ids_only(): void
    {
        $fin = $this->financeManager('F1', $this->sowutuom);
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

    public function test_the_voucher_form_has_no_add_category_or_type_panel_for_anyone(): void
    {
        // Not the officer, and not super_admin (who holds every permission): management lives on the Settings screen only.
        foreach ([$this->finance('F1', $this->sowutuom), $this->superAdmin()] as $user) {
            $page = Livewire::actingAs($user)->test(VoucherForm::class)->assertDontSee('Add a category')->assertDontSee('New category');

            foreach (['addCategory', 'addType'] as $method) {
                $this->assertFalse(method_exists($page->instance(), $method), "{$method} is gone from the voucher form");
            }
        }
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
