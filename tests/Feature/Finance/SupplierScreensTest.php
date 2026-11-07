<?php

namespace Tests\Feature\Finance;

use App\Livewire\Finance\Suppliers;
use App\Livewire\Finance\VoucherForm;
use App\Models\FinSupplier;
use App\Models\FinPvType;
use App\Services\Finance\SupplierService;
use App\Support\ErpNavigation;
use Livewire\Livewire;

/** The Suppliers screen (Finance door writes, Audit door reads), the PV form's quick add and the navigation. */
class SupplierScreensTest extends FinanceTestCase
{
    private function add($by, string $name, array $extra = []): FinSupplier
    {
        $this->actingAs($by);

        return app(SupplierService::class)->create($by, ['name' => $name] + $extra);
    }

    public function test_the_routes_are_gated_and_the_sidebar_lists_the_supplier_screens(): void
    {
        $this->withoutVite();
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $plain = $this->person('E1', $this->sowutuom);

        $this->actingAs($fin)->get(route('finance.suppliers'))->assertOk()->assertSee('Suppliers');
        $this->actingAs($aud)->get(route('audit.suppliers'))->assertOk()->assertSee('Suppliers');
        $this->actingAs($plain)->get(route('finance.suppliers'))->assertRedirect();
        $this->actingAs($plain)->get(route('audit.suppliers'))->assertRedirect();
        $this->actingAs($aud)->get(route('finance.suppliers'))->assertRedirect();

        $navigation = app(ErpNavigation::class);
        $routes = fn ($user, string $module) => collect($navigation->build($user, $module)['sidebar'])->pluck('route')->all();

        $this->assertContains('finance.suppliers', $routes($fin, 'finance'));
        $this->assertContains('finance.suppliers.import', $routes($fin, 'finance'));
        $this->assertContains('audit.suppliers', $routes($aud, 'audit'));
        $this->assertNotContains('finance.suppliers.import', $routes($aud, 'audit'));
    }

    public function test_finance_adds_edits_and_sees_the_list_with_its_own_locations_figures(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $ash = $this->finance('A1', $this->kumasi);
        $supplier = $this->add($fin, 'Listed Already Ltd');
        $this->sent($fin, ['pv_no' => 'W-1', 'payee_kind' => 'supplier', 'supplier_id' => $supplier->id, 'amount' => '120.00']);
        $this->sent($ash, ['pv_no' => 'A-1', 'payee_kind' => 'supplier', 'supplier_id' => $supplier->id, 'amount' => '900.00']);

        $page = Livewire::actingAs($fin)->test(Suppliers::class)
            ->assertSee('Listed Already Ltd')->assertSee('120.00')->assertDontSee('900.00')
            ->assertSee('your location');

        $page->call('startAdd')
            ->set('form.name', 'Brand New Supplies')->set('form.tin', 'C0000000009')->set('form.phone', '0201112233')
            ->call('save')->assertHasNoErrors()->assertSee('Brand New Supplies');
        $this->assertSame('0201112233', FinSupplier::query()->where('name', 'Brand New Supplies')->value('phone'));

        $id = FinSupplier::query()->where('name', 'Brand New Supplies')->value('id');
        $page->call('startEdit', $id)->assertSet('form.tin', 'C0000000009')
            ->set('form.name', 'Brand New Supplies Co')->call('save')->assertHasNoErrors();
        $this->assertSame('Brand New Supplies Co', FinSupplier::query()->find($id)->name);

        // The vouchers panel lists this location's vouchers for the supplier only.
        $page->call('view', $supplier->id)->assertSee('W-1')->assertDontSee('A-1');
    }

    public function test_a_duplicate_is_refused_and_a_look_alike_needs_the_tick(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $this->add($fin, 'ABC Trading Ltd.');

        $page = Livewire::actingAs($fin)->test(Suppliers::class)->call('startAdd')
            ->set('form.name', 'abc trading ltd.')
            ->call('save')->assertHasErrors(['form.name']);
        $this->assertSame(1, FinSupplier::query()->count());

        $page->set('form.name', 'ABC Trading Limited')
            ->assertSee('Already on the list?')->assertSee('ABC Trading Ltd.')   // the look-alikes are shown while typing
            ->call('save')->assertHasErrors(['form.name'])
            ->set('differentSupplier', true)->call('save')->assertHasNoErrors();
        $this->assertSame(2, FinSupplier::query()->count());
    }

    public function test_audit_reads_the_list_but_cannot_add_edit_or_switch_off(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $supplier = $this->add($fin, 'Read Only Ltd');

        $aud = $this->audit('AU1', $this->sowutuom);
        $audit = fn () => Livewire::actingAs($aud)->test(Suppliers::class, ['module' => 'audit']);

        $audit()->assertSee('Read Only Ltd')->assertSee('Suppliers are added and changed by Finance')
            ->assertDontSee('Add a supplier')->assertDontSee('Switch off');

        // A forbidden call ends that component, so each forced call gets a fresh one.
        $audit()->call('startAdd')->assertForbidden();
        $audit()->call('startEdit', $supplier->id)->assertForbidden();
        $audit()->call('toggleActive', $supplier->id, false)->assertForbidden();
        $this->assertTrue($supplier->fresh()->is_active);

        // Even a Finance officer cannot use the Audit door to change anything.
        $both = $this->both('B1', $this->sowutuom);
        Livewire::actingAs($both)->test(Suppliers::class, ['module' => 'audit'])->call('startAdd')->assertForbidden();
    }

    public function test_only_someone_with_deactivate_can_switch_a_supplier_off(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $super = $this->superAdmin();
        $supplier = $this->add($fin, 'Switch Me Ltd');

        Livewire::actingAs($fin)->test(Suppliers::class)->assertDontSee('Switch off')->call('toggleActive', $supplier->id, false)->assertForbidden();
        $this->assertTrue($supplier->fresh()->is_active);

        Livewire::actingAs($super)->test(Suppliers::class)->assertSee('every location')->call('toggleActive', $supplier->id, false)->assertHasNoErrors();
        $this->assertFalse($supplier->fresh()->is_active);

        // A switched-off supplier is hidden by default and found under "Switched off".
        Livewire::actingAs($fin)->test(Suppliers::class)->assertDontSee('Switch Me Ltd')->set('status', 'inactive')->assertSee('Switch Me Ltd');
    }

    public function test_the_voucher_form_adds_a_supplier_on_the_spot_and_chooses_it(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $type = FinPvType::query()->where('name', 'Stationery')->firstOrFail();
        $this->add($fin, 'Mensah Brothers Ltd');

        $form = Livewire::actingAs($fin)->test(VoucherForm::class)
            ->set('form.payee_kind', 'supplier')
            ->assertSee('Supplier not on the list? Add it');

        // A look-alike is shown and must be ticked.
        $form->set('newSupplier.name', 'Mensah Brothers Limited')
            ->assertSee('Already on the list?')
            ->call('addSupplier')->assertHasErrors(['newSupplier.name']);
        $this->assertSame(1, FinSupplier::query()->count());

        $form->set('newSupplier.name', 'Fresh Quick Add Co')->set('newSupplier.phone', '0201112233')
            ->call('addSupplier')->assertHasNoErrors();

        $added = FinSupplier::query()->where('name', 'Fresh Quick Add Co')->firstOrFail();
        $form->assertSet('form.supplier_id', (string) $added->id)->assertSet('newSupplier.name', '');

        // ...and the voucher can now be saved to it.
        $form->set('form.pv_date', '2026-11-05')->set('form.pv_no', 'QUICK-1')->set('form.category_id', (string) $type->category_id)
            ->set('form.type_id', (string) $type->id)->set('form.details', 'Office stationery')->set('form.amount', '300.00')
            ->call('saveDraft')->assertHasNoErrors();
        $this->assertSame($added->id, \App\Models\FinPaymentVoucher::query()->where('pv_no', 'QUICK-1')->value('supplier_id'));
    }
}
