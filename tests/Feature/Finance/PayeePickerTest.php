<?php

namespace Tests\Feature\Finance;

use App\Livewire\Finance\VoucherForm;
use App\Models\Employee;
use App\Models\FinPaymentVoucher;
use App\Models\FinPvType;
use App\Models\FinSupplier;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Finance\FinanceScope;
use App\Services\Finance\SupplierService;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/** Step C: the payee pickers (server-side search, region-only districts, ids re-resolved on save). */
class PayeePickerTest extends FinanceTestCase
{
    private function scope(): FinanceScope
    {
        return app(FinanceScope::class);
    }

    private function staff(string $staffId, $district, string $name, array $extra = []): Employee
    {
        $employee = $this->employee($staffId, $district);
        $employee->forceFill(['full_name' => $name] + $extra)->save();

        return $employee->fresh();
    }

    private function assertInvalid(string $field, callable $call): void
    {
        try {
            $call();
            $this->fail("Expected a validation error on {$field}.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    // ------------------------------------------------------------------ employees and retirees

    public function test_the_people_search_is_scoped_to_the_location_and_kinds_are_never_mixed(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $this->staff('W-100', $this->sowutuom, 'Ama Mensah');
        $this->staff('W-101', $this->odorkor, 'Amanda Boateng');                       // same region, another district: same location
        $this->staff('H-100', $this->headOffice, 'Ama Head Office');                   // Head Office is its own location
        $this->staff('A-100', $this->kumasi, 'Ama Ashanti');                           // another region
        $this->staff('W-102', $this->sowutuom, 'Ama Retired', ['is_active' => false, 'deactivation_reason' => 'retired']);
        $this->staff('W-103', $this->sowutuom, 'Ama Transferred', ['is_active' => false, 'deactivation_reason' => 'transfer']);

        $names = fn (array $rows) => array_column($rows, 'label');

        $this->assertEqualsCanonicalizing(['Ama Mensah', 'Amanda Boateng'], $names($this->scope()->searchEmployees($fin, 'employee', 'ama')), 'only this location, only current staff');
        $this->assertSame(['Ama Retired'], $names($this->scope()->searchEmployees($fin, 'retiree', 'ama')), 'retirees only; a transfer has not left service');

        $this->assertSame(['Ama Head Office'], $names($this->scope()->searchEmployees($this->finance('F2', $this->headOffice), 'employee', 'ama')));
        $this->assertSame(['Ama Ashanti'], $names($this->scope()->searchEmployees($this->finance('F3', $this->kumasi), 'employee', 'ama')));

        $this->assertSame(['Ama Mensah'], $names($this->scope()->searchEmployees($fin, 'employee', 'W-100')), 'a staff ID finds a person too');
        $this->assertSame([], $this->scope()->searchEmployees($fin, 'supplier', 'ama'), 'a kind that is not a person finds nothing');
        $this->assertSame([], $this->scope()->searchEmployees($this->homeless('H9', ['employee', 'finance_officer']), 'employee', 'ama'), 'no location, no people');
    }

    public function test_a_search_needs_two_characters_returns_at_most_twenty_and_treats_wildcards_as_text(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);

        foreach (range(1, 25) as $i) {
            $this->staff('BULK-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), $this->sowutuom, 'Kojo Bulk '.$i);
        }

        $this->staff('P-1', $this->sowutuom, '100% Honest');

        $this->assertSame([], $this->scope()->searchEmployees($fin, 'employee', 'k'), 'one character');
        $this->assertSame([], $this->scope()->searchEmployees($fin, 'employee', ' k '), 'spaces do not count');
        $this->assertCount(20, $this->scope()->searchEmployees($fin, 'employee', 'Kojo'), 'never more than 20');
        $this->assertSame(['100% Honest'], array_column($this->scope()->searchEmployees($fin, 'employee', '0% H'), 'label'));
        $this->assertSame([], $this->scope()->searchEmployees($fin, 'employee', '%%'), 'a wildcard is not a wildcard');
        $this->assertSame([], $this->scope()->searchEmployees($fin, 'employee', '__'));
    }

    public function test_people_rows_carry_a_disambiguator_and_never_contact_details(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $this->staff('W-100', $this->sowutuom, 'Ama Mensah');

        $row = $this->scope()->searchEmployees($fin, 'employee', 'Ama Mensah')[0];

        $this->assertSame(['value', 'label', 'description'], array_keys($row));
        $this->assertSame('Staff ID W-100 · Distribution', $row['description']);
        $this->assertStringNotContainsString('@', json_encode($row), 'no e-mail address');
    }

    // ------------------------------------------------------------------ suppliers

    public function test_the_supplier_search_lists_active_suppliers_only_with_a_code_and_never_a_tin_or_phone(): void
    {
        $officer = $this->finance('F1', $this->sowutuom);
        $service = app(SupplierService::class);
        $this->actingAs($officer);
        $acme = $service->create($officer, ['name' => 'Acme Pipes', 'code' => 'AC-01', 'tin' => 'P0012345678', 'phone' => '0244123456']);
        $service->create($officer, ['name' => 'Acme Valves']);
        $off = $service->create($officer, ['name' => 'Acme Retired Ltd']);
        FinSupplier::query()->whereKey($off->id)->update(['is_active' => false]);

        $rows = $service->search('acme');

        $this->assertSame(['Acme Pipes', 'Acme Valves'], array_column($rows, 'label'), 'active only, by name');
        $this->assertSame(['Code AC-01', ''], array_column($rows, 'description'));
        $this->assertStringNotContainsString('P0012345678', json_encode($rows));
        $this->assertStringNotContainsString('0244123456', json_encode($rows));
        $this->assertSame([$acme->id], array_column($service->search('AC-01'), 'value'), 'a code finds it too');
        $this->assertSame([], $service->search('a'), 'two characters at least');
        $this->assertSame([], $service->search('P0012345678'), 'a TIN is not searchable here');
        $this->assertSame([], $service->search('%%'));

        foreach (range(1, 25) as $i) {
            $service->create($officer, ['name' => 'Bulk Supplier '.$i]);
        }

        $this->assertCount(20, $service->search('Bulk'));
    }

    // ------------------------------------------------------------------ districts: region based only

    public function test_districts_are_only_those_of_the_users_own_location(): void
    {
        $west = $this->finance('F1', $this->sowutuom);
        $ash = $this->finance('F2', $this->kumasi);
        $ho = $this->finance('F3', $this->headOffice);
        $names = fn ($user, $location = null) => $this->scope()->districts($user, $location)->pluck('district_name')->all();

        $this->assertSame(['Odorkor', 'Sowutuom'], $names($west), 'the region\'s districts, not Head Office, not another region');
        $this->assertSame(['Kumasi'], $names($ash));
        $this->assertSame(['Head Office'], $names($ho));

        // A normal user cannot ask for another location's districts.
        $other = ['region_id' => $this->kumasi->region_id, 'district_id' => $this->kumasi->id];
        $this->assertSame(['Odorkor', 'Sowutuom'], $names($west, $other));

        // super_admin gets the districts of the location they picked, and nothing until they pick one.
        $super = $this->superAdmin();
        $this->assertSame([], $names($super));
        $this->assertSame(['Kumasi'], $names($super, $other));
        $this->assertSame(['Head Office'], $names($super, ['region_id' => $this->headOffice->region_id, 'district_id' => $this->headOffice->id]));
        $this->assertSame(['Odorkor', 'Sowutuom'], $names($super, ['region_id' => $this->sowutuom->region_id, 'district_id' => $this->sowutuom->id]));
        $this->assertSame([], $names($this->homeless('H1', ['employee', 'finance_officer'])));
    }

    public function test_a_district_payee_outside_the_location_is_refused_on_save_and_an_old_one_is_kept(): void
    {
        $west = $this->finance('F1', $this->sowutuom);
        $w = $this->workflow();
        $this->actingAs($west);

        $this->assertNotNull($w->createDraft($west, $this->pvData(['pv_no' => 'D-1', 'payee_district_id' => $this->odorkor->id])), 'a district of its own region');

        foreach (['D-2' => $this->kumasi, 'D-3' => $this->headOffice] as $number => $district) {
            $this->assertInvalid('payee_district_id', fn () => $w->createDraft($west, $this->pvData(['pv_no' => $number, 'payee_district_id' => $district->id])));
        }

        $this->assertInvalid('payee_district_id', fn () => $w->createDraft($west, $this->pvData(['pv_no' => 'D-4', 'payee_district_id' => 999999])));

        // Head Office pays Head Office only.
        $ho = $this->finance('F2', $this->headOffice);
        $this->actingAs($ho);
        $this->assertInvalid('payee_district_id', fn () => $w->createDraft($ho, $this->pvData(['pv_no' => 'D-5', 'payee_district_id' => $this->sowutuom->id])));
        $this->assertNotNull($w->createDraft($ho, $this->pvData(['pv_no' => 'D-6', 'payee_district_id' => $this->headOffice->id])));

        // A voucher that already has a district keeps it when edited, even if it would not be offered today.
        $this->actingAs($west);
        $old = $w->createDraft($west, $this->pvData(['pv_no' => 'D-7', 'payee_district_id' => $this->sowutuom->id]));
        FinPaymentVoucher::query()->whereKey($old->id)->update(['payee_district_id' => $this->kumasi->id]);
        $saved = $w->update($west, $old->fresh(), $this->pvData(['pv_no' => 'D-7', 'payee_district_id' => $this->kumasi->id, 'amount' => '8.00']), $old->lock_version);
        $this->assertSame($this->kumasi->id, $saved->payee_district_id);
    }

    // ------------------------------------------------------------------ ids are re-resolved on every save

    public function test_a_tampered_id_is_refused_on_save_for_every_payee_kind(): void
    {
        $west = $this->finance('F1', $this->sowutuom);
        $w = $this->workflow();
        $this->actingAs($west);
        $other = $this->staff('A-100', $this->kumasi, 'Kofi Ashanti');
        $retired = $this->staff('W-9', $this->sowutuom, 'Old Timer', ['is_active' => false, 'deactivation_reason' => 'retired']);
        $current = $this->staff('W-8', $this->sowutuom, 'Current Person');
        $inactive = app(SupplierService::class)->create($west, ['name' => 'Gone Supplies']);
        FinSupplier::query()->whereKey($inactive->id)->update(['is_active' => false]);

        $this->assertInvalid('payee_employee_id', fn () => $w->createDraft($west, $this->pvData(['pv_no' => 'T-1', 'payee_kind' => 'employee', 'payee_employee_id' => $other->id])));
        $this->assertInvalid('payee_employee_id', fn () => $w->createDraft($west, $this->pvData(['pv_no' => 'T-2', 'payee_kind' => 'employee', 'payee_employee_id' => $retired->id])));
        $this->assertInvalid('payee_employee_id', fn () => $w->createDraft($west, $this->pvData(['pv_no' => 'T-3', 'payee_kind' => 'retiree', 'payee_employee_id' => $current->id])));
        $this->assertInvalid('payee_employee_id', fn () => $w->createDraft($west, $this->pvData(['pv_no' => 'T-4', 'payee_kind' => 'employee', 'payee_employee_id' => 0])));
        $this->assertInvalid('supplier_id', fn () => $w->createDraft($west, $this->pvData(['pv_no' => 'T-5', 'payee_kind' => 'supplier', 'supplier_id' => $inactive->id])));
        $this->assertInvalid('supplier_id', fn () => $w->createDraft($west, $this->pvData(['pv_no' => 'T-6', 'payee_kind' => 'supplier', 'supplier_id' => 424242])));
        $this->assertSame(0, FinPaymentVoucher::query()->where('pv_no', 'like', 'T-%')->count());
    }

    // ------------------------------------------------------------------ the form's pickers (Livewire)

    public function test_the_forms_search_methods_return_scoped_rows_and_nothing_for_the_wrong_kind(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $this->staff('W-100', $this->sowutuom, 'Ama Mensah');
        $this->staff('A-100', $this->kumasi, 'Ama Ashanti');
        app(SupplierService::class)->create($fin, ['name' => 'Amazing Supplies']);

        $form = Livewire::actingAs($fin)->test(VoucherForm::class)->set('form.payee_kind', 'employee');
        $form->call('searchPeople', 'Ama')->assertReturned(fn (array $rows) => array_column($rows, 'label') === ['Ama Mensah']);
        $form->call('searchPeople', 'A')->assertReturned([]);
        $form->call('searchSuppliers', 'Ama')->assertReturned([]);   // not on the supplier kind

        $form->set('form.payee_kind', 'supplier');
        $form->call('searchSuppliers', 'Ama')->assertReturned(fn (array $rows) => array_column($rows, 'label') === ['Amazing Supplies']);
        $form->call('searchPeople', 'Ama')->assertReturned([]);

        $form->set('form.payee_kind', 'retiree')->call('searchPeople', 'Ama')->assertReturned([]);
    }

    public function test_changing_the_kind_or_the_location_clears_the_picked_payee(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $person = $this->staff('W-100', $this->sowutuom, 'Ama Mensah');

        Livewire::actingAs($fin)->test(VoucherForm::class)
            ->set('form.payee_employee_id', (string) $person->id)
            ->set('form.payee_kind', 'district')->assertSet('form.payee_employee_id', '')
            ->set('form.payee_district_id', (string) $this->odorkor->id)
            ->set('form.payee_kind', 'supplier')->assertSet('form.payee_district_id', '')->assertSet('form.supplier_id', '');
    }

    public function test_the_employee_picker_saves_a_voucher_and_refuses_an_id_from_another_location_through_the_form(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $mine = $this->staff('W-100', $this->sowutuom, 'Ama Mensah');
        $theirs = $this->staff('A-100', $this->kumasi, 'Kofi Ashanti');
        $type = FinPvType::query()->where('name', 'Medical')->firstOrFail();
        $fill = fn ($form, string $no, int $employeeId) => $form
            ->set('form.pv_no', $no)->set('form.payee_kind', 'employee')->set('form.payee_employee_id', (string) $employeeId)
            ->set('form.category_id', (string) $type->category_id)->set('form.type_id', (string) $type->id)
            ->set('form.details', 'Claim')->set('form.amount', '10.00');

        $fill(Livewire::actingAs($fin)->test(VoucherForm::class), 'PK-1', $mine->id)->call('saveDraft')->assertHasNoErrors();
        $this->assertSame('Ama Mensah', FinPaymentVoucher::query()->where('pv_no', 'PK-1')->value('payee_name'));

        $fill(Livewire::actingAs($fin)->test(VoucherForm::class), 'PK-2', $theirs->id)->call('saveDraft')->assertHasErrors(['form.payee_employee_id']);
        $this->assertSame(0, FinPaymentVoucher::query()->where('pv_no', 'PK-2')->count());
    }

    public function test_the_district_picker_offers_only_the_locations_districts_and_super_admin_sees_the_chosen_ones(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);

        Livewire::actingAs($fin)->test(VoucherForm::class)->set('form.payee_kind', 'district')
            ->assertSee('Odorkor')->assertSee('Sowutuom')->assertDontSee('Kumasi')->assertDontSee('Head Office');

        // (super_admin's own location picker lists every district, so the picker's options are read, not the page text.)
        $options = fn ($page) => array_column($page->viewData('districtOptions'), 'label');
        $page = Livewire::actingAs($this->superAdmin())->test(VoucherForm::class)->set('form.payee_kind', 'district');
        $this->assertSame([], $options($page), 'no location chosen yet, no districts');
        $this->assertSame(['Kumasi'], $options($page->set('locationId', (string) $this->kumasi->id)));
        $this->assertEqualsCanonicalizing(['Odorkor', 'Sowutuom'], $options($page->set('locationId', (string) $this->sowutuom->id)));
    }

    public function test_no_match_offers_a_new_supplier_only_to_those_who_may_add_one_and_the_added_supplier_is_chosen(): void
    {
        $officer = $this->finance('F1', $this->sowutuom);

        // The officer may add suppliers: the "add as new" button is there and a typed name is carried into the panel.
        $page = Livewire::actingAs($officer)->test(VoucherForm::class)->set('form.payee_kind', 'supplier')
            ->assertSeeHtml('startNewSupplier(query)')->assertSet('showSupplierAdd', false);
        $page->call('startNewSupplier', '  Brand New Traders  ')->assertSet('showSupplierAdd', true)->assertSet('newSupplier.name', 'Brand New Traders');
        $page->call('addSupplier')->assertHasNoErrors()->assertSet('showSupplierAdd', false);

        $id = FinSupplier::query()->where('name', 'Brand New Traders')->value('id');
        $page->assertSet('form.supplier_id', (string) $id);
        $page->call('searchSuppliers', 'Brand New')->assertReturned(fn (array $rows) => array_column($rows, 'value') === [$id]);

        // Someone who may not add suppliers has no button and the method refuses.
        Role::query()->where('name', 'finance_officer')->firstOrFail()->permissions()->detach(Permission::query()->where('name', 'finance.manage_suppliers')->value('id'));
        $limited = $this->finance('F9', $this->sowutuom)->fresh();
        Livewire::actingAs($limited)->test(VoucherForm::class)->set('form.payee_kind', 'supplier')
            ->assertDontSeeHtml('startNewSupplier(query)')
            ->call('startNewSupplier', 'Sneaky Ltd')->assertForbidden();
    }

    public function test_the_picker_is_the_shared_accessible_combobox_with_its_search_limits(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);

        $html = Livewire::actingAs($fin)->test(VoucherForm::class)->set('form.payee_kind', 'employee')->html();

        $this->assertStringContainsString('role="combobox"', $html);
        $this->assertStringContainsString('aria-activedescendant', $html);
        $this->assertStringContainsString('role="listbox"', $html);
        $this->assertStringContainsString('aria-live="polite"', $html, 'result counts are announced');
        $this->assertStringContainsString('minChars: 2', $html);
        $this->assertStringContainsString('limit: 20', $html);
        $this->assertStringContainsString('searchPeople', $html);
        $this->assertStringContainsString('Clear Employee', $html);
        $this->assertStringContainsString('No one matches that', $html);
        $this->assertStringContainsString('Type at least 2 characters to search.', $html);
    }
}
