<?php

namespace Tests\Feature\Finance;

use App\Livewire\Finance\Concerns\ScopesFinanceByActor;
use App\Models\FinPaymentVoucher;
use App\Models\FinPvEvent;
use App\Services\Finance\FinanceScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/** Which vouchers a user reaches: Head Office OR a region without it, drafts for Finance only, 404 outside. */
class FinanceScopeTest extends FinanceTestCase
{
    public function test_each_location_reaches_only_its_own_vouchers_and_head_office_is_not_its_regions_sibling(): void
    {
        $ho = $this->finance('HO1', $this->headOffice);
        $west = $this->finance('W1', $this->sowutuom);       // Accra West shares its region id with Head Office
        $westOther = $this->finance('W2', $this->odorkor);   // same region, another district: same books as W1
        $ash = $this->finance('A1', $this->kumasi);

        $hoPv = $this->draft($ho, ['pv_no' => 'HO-1']);
        $westPv = $this->draft($west, ['pv_no' => 'W-1']);
        $ashPv = $this->draft($ash, ['pv_no' => 'A-1']);

        $scope = app(FinanceScope::class);
        $ids = fn ($user) => $scope->pvs($user)->pluck('id')->sort()->values()->all();

        $this->assertSame([$hoPv->id], $ids($ho));
        $this->assertSame([$westPv->id], $ids($west));
        $this->assertSame([$westPv->id], $ids($westOther));
        $this->assertSame([$ashPv->id], $ids($ash));
        $this->assertSame(collect([$hoPv, $westPv, $ashPv])->pluck('id')->sort()->values()->all(), $ids($this->superAdmin()));
    }

    public function test_another_locations_voucher_answers_404_for_reading_and_writing(): void
    {
        $west = $this->finance('W1', $this->sowutuom);
        $ash = $this->finance('A1', $this->kumasi);
        $pv = $this->draft($west);

        $this->expectNotFound(fn () => app(FinanceScope::class)->findPv($ash, $pv->id));
        $this->expectNotFound(fn () => $this->workflow()->submit($ash, $pv, $pv->lock_version));
        $this->expectNotFound(fn () => $this->workflow()->void($ash, $pv, 'no'));
    }

    public function test_a_draft_is_visible_to_finance_only_and_audit_never_finds_it(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $draft = $this->draft($fin);

        $scope = app(FinanceScope::class);

        $this->assertTrue($scope->containsPv($fin, $draft));
        $this->assertFalse($scope->containsPv($aud, $draft, FinanceScope::AS_AUDIT));
        $this->expectNotFound(fn () => $this->workflow()->auditAcknowledge($aud, $draft));

        // Once sent, Audit reaches it; a draft voided before it ever reached Audit stays invisible to them.
        $sent = $this->sent($fin, ['pv_no' => 'PV-2']);
        $this->assertTrue($scope->containsPv($aud, $sent, FinanceScope::AS_AUDIT));

        $voidedDraft = $this->draft($fin, ['pv_no' => 'PV-3']);
        $this->workflow()->void($fin, $voidedDraft, 'Entered by mistake');
        $this->assertFalse($scope->containsPv($aud, $voidedDraft->fresh(), FinanceScope::AS_AUDIT));
    }

    public function test_a_login_with_no_employee_region_has_nothing_and_super_admin_must_pick_a_location_to_write(): void
    {
        $this->draft($this->finance('F1', $this->sowutuom));
        $homeless = $this->homeless('H1', ['employee', 'finance_officer']);
        $scope = app(FinanceScope::class);

        $this->assertSame(0, $scope->pvs($homeless)->count());
        $this->expectNotFound(fn () => $this->workflow()->createDraft($homeless, $this->pvData(['pv_no' => 'X-1'])));

        $super = $this->superAdmin();
        $this->actingAs($super);

        try {
            $this->workflow()->createDraft($super, $this->pvData(['pv_no' => 'X-2']));
            $this->fail('super_admin must choose a location.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('location', $exception->errors());
        }

        $pv = $this->workflow()->createDraft($super, $this->pvData(['pv_no' => 'X-3']), [], $this->kumasi->id);
        $this->assertSame($this->ashanti->id, $pv->region_id);
        $this->assertSame($this->kumasi->id, $pv->district_id);
    }

    public function test_events_and_attachments_follow_the_vouchers_reach(): void
    {
        $west = $this->finance('W1', $this->sowutuom);
        $ash = $this->finance('A1', $this->kumasi);
        $this->sent($west);

        $scope = app(FinanceScope::class);

        $this->assertSame(1, $scope->events($west)->count());
        $this->assertSame(0, $scope->events($ash)->count());
        $this->assertSame(0, $scope->attachments($ash)->count());
    }

    public function test_the_livewire_trait_agrees_with_the_scope_for_every_kind_of_user(): void
    {
        $west = $this->finance('W1', $this->sowutuom);
        $ho = $this->finance('HO1', $this->headOffice);
        $ash = $this->finance('A1', $this->kumasi);
        $aud = $this->audit('AU1', $this->sowutuom);
        $super = $this->superAdmin();
        $homeless = $this->homeless('H1', ['employee', 'finance_officer']);

        $this->sent($west, ['pv_no' => 'W-1']);
        $this->draft($west, ['pv_no' => 'W-2']);
        $this->sent($ho, ['pv_no' => 'HO-1']);
        $this->draft($ash, ['pv_no' => 'A-1']);

        $harness = new class
        {
            use ScopesFinanceByActor;

            public function ids(string $as): array
            {
                return $this->pvs($as)->pluck('id')->sort()->values()->all();
            }
        };

        $scope = app(FinanceScope::class);

        foreach ([$west, $ho, $ash, $aud, $super, $homeless] as $user) {
            Auth::login($user);

            foreach ([FinanceScope::AS_FINANCE, FinanceScope::AS_AUDIT] as $as) {
                $this->assertSame($scope->pvs($user, $as)->pluck('id')->sort()->values()->all(), $harness->ids($as), "{$user->staff_id} as {$as}");
            }
        }
    }

    public function test_the_employee_picker_lists_current_staff_and_retirees_of_the_location_only(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $this->employee('CUR1', $this->sowutuom);
        $this->employee('OTH1', $this->kumasi);
        $this->employee('HOS1', $this->headOffice);

        foreach ([['RET1', 'retired'], ['RES1', 'resigned'], ['TRF1', 'transfer']] as [$staffId, $reason]) {
            $employee = $this->employee($staffId, $this->sowutuom);
            $employee->update(['is_active' => false, 'deactivation_reason' => $reason]);
        }

        $scope = app(FinanceScope::class);
        $staffIds = fn (string $kind) => $scope->employees($fin, $kind)->pluck('staff_id')->all();

        $this->assertContains('CUR1', $staffIds('employee'));
        $this->assertNotContains('OTH1', $staffIds('employee'));
        $this->assertNotContains('HOS1', $staffIds('employee'));
        $this->assertNotContains('RET1', $staffIds('employee'));

        $retirees = $staffIds('retiree');
        $this->assertEqualsCanonicalizing(['RET1', 'RES1'], $retirees);
        $this->assertNotContains('TRF1', $retirees); // a transfer is not leaving service
    }

    private function expectNotFound(callable $call): void
    {
        try {
            $call();
            $this->fail('Expected a 404.');
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException|\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->assertTrue(true);
        }
    }
}
