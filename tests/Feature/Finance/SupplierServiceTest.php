<?php

namespace Tests\Feature\Finance;

use App\Models\AuditLog;
use App\Models\FinSupplier;
use App\Services\Finance\SupplierService;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** The only writer of the supplier list: required and optional fields, duplicates, look-alikes, permissions and the audit trail. */
class SupplierServiceTest extends FinanceTestCase
{
    private function suppliers(): SupplierService
    {
        return app(SupplierService::class);
    }

    private function assertValidationFails(string $field, callable $call, ?string $contains = null): void
    {
        try {
            $call();
            $this->fail("Expected a validation error on {$field}.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());

            if ($contains !== null) {
                $this->assertStringContainsString($contains, $exception->errors()[$field][0]);
            }
        }
    }

    private function assertForbidden(callable $call): void
    {
        try {
            $call();
            $this->fail('Expected a 403.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_only_the_name_is_required_and_blanks_are_stored_as_null_not_empty_text(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $this->actingAs($fin);

        $supplier = $this->suppliers()->create($fin, ['name' => '  Kofi   Mensah Ent ', 'code' => '  ', 'tin' => '', 'phone' => ' ']);

        $this->assertSame('Kofi Mensah Ent', $supplier->name);
        $this->assertSame('KOFI MENSAH ENT', $supplier->name_key);
        $fresh = FinSupplier::query()->findOrFail($supplier->id);
        $this->assertNull($fresh->code);
        $this->assertNull($fresh->tin);
        $this->assertNull($fresh->phone);
        $this->assertTrue($fresh->is_active);
        $this->assertSame($fin->id, $fresh->created_by);
        $this->assertSame($this->sowutuom->region_id, $fresh->first_region_id);
        $this->assertSame($this->sowutuom->id, $fresh->first_district_id);

        // Two suppliers with no TIN are fine (several NULLs are allowed by the unique index).
        $this->suppliers()->create($fin, ['name' => 'Another Trader']);
        $this->assertSame(2, FinSupplier::query()->whereNull('tin')->count());
    }

    public function test_a_wrong_name_tin_or_phone_is_refused_and_every_problem_is_reported_together(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $this->actingAs($fin);

        try {
            $this->suppliers()->create($fin, ['name' => '   ', 'tin' => '12', 'phone' => 'abc', 'code' => str_repeat('x', 41)]);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $exception) {
            $this->assertEqualsCanonicalizing(['name', 'tin', 'phone', 'code'], array_keys($exception->errors()));
        }

        $this->assertSame(0, FinSupplier::query()->count());
    }

    public function test_phone_and_tin_are_cleaned_and_a_lost_leading_zero_is_restored(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $this->actingAs($fin);

        $a = $this->suppliers()->create($fin, ['name' => 'A Traders', 'phone' => '024 412-3456', 'tin' => 'c 0012 345678']);
        $b = $this->suppliers()->create($fin, ['name' => 'B Traders', 'phone' => '244123456']); // Excel dropped the zero
        $c = $this->suppliers()->create($fin, ['name' => 'C Traders', 'phone' => '+233 24 412 3456']);

        $this->assertSame('0244123456', $a->phone);
        $this->assertSame('C0012345678', $a->tin);
        $this->assertSame('0244123456', $b->phone);
        $this->assertSame('+233244123456', $c->phone);
    }

    public function test_the_same_name_or_tin_is_refused_naming_the_supplier_already_on_the_list(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $this->actingAs($fin);
        $this->suppliers()->create($fin, ['name' => 'Accra Stationery Ltd', 'tin' => 'C0012345678']);

        // Case and spacing do not make a new supplier.
        $this->assertValidationFails('name', fn () => $this->suppliers()->create($fin, ['name' => ' accra   STATIONERY ltd ']), '"Accra Stationery Ltd" is already on the supplier list');
        $this->assertValidationFails('tin', fn () => $this->suppliers()->create($fin, ['name' => 'Someone Else', 'tin' => 'c0012345678']), 'Accra Stationery Ltd');

        // A switched-off supplier is still "on the list": the message says how to get it back.
        FinSupplier::query()->update(['is_active' => false]);
        $this->assertValidationFails('name', fn () => $this->suppliers()->create($fin, ['name' => 'Accra Stationery Ltd']), 'switched off');

        $this->assertSame(1, FinSupplier::query()->count());
    }

    public function test_a_look_alike_name_is_a_warning_the_person_must_acknowledge(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $this->actingAs($fin);
        $this->suppliers()->create($fin, ['name' => 'ABC Trading Ltd.']);
        $this->suppliers()->create($fin, ['name' => 'Boateng Brothers']);

        // Different punctuation / suffix, a one-letter slip, and a name the listed one begins with.
        foreach (['ABC Trading Limited', 'Boateng Brothres', 'ABC Trading Centre'] as $name) {
            $this->assertValidationFails('name', fn () => $this->suppliers()->create($fin, ['name' => $name]), 'Looks like a supplier that is already listed');
        }

        $this->assertSame(2, FinSupplier::query()->count());

        // A really different name needs no tick; a look-alike goes in once the person says it is different.
        $this->suppliers()->create($fin, ['name' => 'Zenith Stores']);
        $this->suppliers()->create($fin, ['name' => 'ABC Trading Limited'], acknowledgeSimilar: true);
        $this->assertSame(4, FinSupplier::query()->count());
    }

    public function test_near_matches_lists_the_exact_and_the_similar_ones_for_the_screen(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $this->actingAs($fin);
        $abc = $this->suppliers()->create($fin, ['name' => 'ABC Trading Ltd']);

        $this->assertSame(['ABC Trading Ltd'], array_column($this->suppliers()->nearMatches('abc trading limited'), 'name'));
        $this->assertSame(['ABC Trading Ltd'], array_column($this->suppliers()->nearMatches('ABC TRADING LTD'), 'name'));
        $this->assertSame([], $this->suppliers()->nearMatches('Totally Different Co'));
        $this->assertSame([], $this->suppliers()->nearMatches('   '));
        $this->assertSame([], $this->suppliers()->nearMatches('ABC Trading Ltd', $abc->id), 'a supplier is not a look-alike of itself');
    }

    public function test_adding_and_editing_need_manage_suppliers_and_switching_off_needs_deactivate(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $plain = $this->person('E1', $this->sowutuom);
        $super = $this->superAdmin();

        $this->assertForbidden(fn () => $this->suppliers()->create($aud, ['name' => 'Nope']));
        $this->assertForbidden(fn () => $this->suppliers()->create($plain, ['name' => 'Nope']));

        $this->actingAs($fin);
        $supplier = $this->suppliers()->create($fin, ['name' => 'Yes Traders']);

        $this->assertForbidden(fn () => $this->suppliers()->update($aud, $supplier->id, ['name' => 'Changed']));
        // finance_officer is deliberately NOT given deactivate_suppliers (it affects every location).
        $this->assertForbidden(fn () => $this->suppliers()->setActive($fin, $supplier->id, false));
        $this->assertTrue($supplier->fresh()->is_active);

        $this->actingAs($super);
        $this->assertFalse($this->suppliers()->setActive($super, $supplier->id, false)->is_active);
        $this->assertTrue($this->suppliers()->setActive($super, $supplier->id, true)->is_active);
        $this->assertSame(['finance.supplier_deactivated', 'finance.supplier_reactivated'], AuditLog::query()->where('target_type', 'fin_suppliers')->whereIn('action', ['finance.supplier_deactivated', 'finance.supplier_reactivated'])->orderBy('id')->pluck('action')->all());
    }

    public function test_renaming_never_rewrites_a_voucher_and_editing_follows_the_same_duplicate_rules(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $this->actingAs($fin);
        $old = $this->suppliers()->create($fin, ['name' => 'Old Name Supplies', 'tin' => 'C0000000001']);
        $other = $this->suppliers()->create($fin, ['name' => 'Quite Another Business', 'tin' => 'C0000000002']);
        $pv = $this->draft($fin, ['pv_no' => 'SUP-1', 'payee_kind' => 'supplier', 'supplier_id' => $old->id]);

        $this->suppliers()->update($fin, $old->id, ['name' => 'New Name Supplies', 'tin' => 'C0000000001']);

        $this->assertSame('New Name Supplies', $old->fresh()->name);
        $this->assertSame('Old Name Supplies', $pv->fresh()->payee_name, 'the voucher keeps the name it was saved with');
        $this->assertSame($old->id, $pv->fresh()->supplier_id);

        // Taking another supplier's name or TIN is refused, naming it.
        $this->assertValidationFails('name', fn () => $this->suppliers()->update($fin, $old->id, ['name' => 'quite another business']), 'Quite Another Business');
        $this->assertValidationFails('tin', fn () => $this->suppliers()->update($fin, $old->id, ['name' => 'New Name Supplies', 'tin' => 'C0000000002']), 'Quite Another Business');

        // Saving a supplier without changing anything writes nothing and is not tripped by its own name.
        $before = AuditLog::query()->where('action', 'finance.supplier_updated')->count();
        $this->suppliers()->update($fin, $other->id, ['name' => 'Quite Another Business', 'tin' => 'C0000000002']);
        $this->assertSame($before, AuditLog::query()->where('action', 'finance.supplier_updated')->count());
    }

    public function test_a_switched_off_supplier_cannot_start_a_new_voucher_but_stays_on_the_old_ones(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $super = $this->superAdmin();
        $this->actingAs($fin);
        $supplier = $this->suppliers()->create($fin, ['name' => 'Soon Gone Ltd']);
        $pv = $this->draft($fin, ['pv_no' => 'SUP-2', 'payee_kind' => 'supplier', 'supplier_id' => $supplier->id]);

        $this->actingAs($super);
        $this->suppliers()->setActive($super, $supplier->id, false);
        $this->actingAs($fin);

        // The voucher already paid to it can still be edited with it...
        $saved = $this->workflow()->update($fin, $pv, $this->pvData(['pv_no' => 'SUP-2', 'payee_kind' => 'supplier', 'supplier_id' => $supplier->id, 'amount' => '5.00']), $pv->lock_version);
        $this->assertSame(500, $saved->amount);

        // ...but nobody can choose it for a new one.
        $this->assertValidationFails('supplier_id', fn () => $this->workflow()->createDraft($fin, $this->pvData(['pv_no' => 'SUP-3', 'payee_kind' => 'supplier', 'supplier_id' => $supplier->id])));
    }

    public function test_audit_rows_hold_ids_and_field_names_never_a_name_tin_or_phone(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $super = $this->superAdmin();
        $this->actingAs($fin);

        $supplier = $this->suppliers()->create($fin, ['name' => 'Confidential Wares', 'tin' => 'TIN-SECRET-77', 'phone' => '0201112223']);
        $this->suppliers()->update($fin, $supplier->id, ['name' => 'Confidential Wares', 'tin' => 'TIN-SECRET-77', 'phone' => '0201112224']);
        $this->actingAs($super);
        $this->suppliers()->setActive($super, $supplier->id, false);

        $rows = AuditLog::query()->where('target_type', 'fin_suppliers')->orderBy('id')->get();
        $this->assertSame(['finance.supplier_created', 'finance.supplier_updated', 'finance.supplier_deactivated'], $rows->pluck('action')->all());
        $this->assertSame(['fields' => ['phone']], $rows[1]->metadata);

        $dump = $rows->toJson();

        foreach (['Confidential', 'TIN-SECRET-77', '0201112223', '0201112224'] as $secret) {
            $this->assertStringNotContainsString($secret, $dump);
        }
    }
}
