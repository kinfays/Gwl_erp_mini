<?php

namespace Tests\Feature\Finance;

use App\Livewire\Audit\Queue;
use App\Livewire\Audit\VoucherShow as AuditVoucherShow;
use App\Livewire\Finance\VoucherForm;
use App\Livewire\Finance\VoucherShow;
use App\Livewire\Finance\Vouchers;
use App\Models\FinPaymentVoucher as Pv;
use App\Models\FinPvType;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/** The screens: lists, marks, the form, the detail pages and the route middleware. */
class FinanceAuditScreensTest extends FinanceTestCase
{
    public function test_the_routes_are_gated_by_module_and_permission(): void
    {
        $this->withoutVite();
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $plain = $this->person('E1', $this->sowutuom, ['employee']);

        $this->get(route('finance.home'))->assertRedirect();
        $this->actingAs($fin)->get(route('finance.home'))->assertOk()->assertSee('Payment vouchers');
        $this->actingAs($fin)->get(route('finance.pvs.create'))->assertOk();
        $this->actingAs($fin)->get(route('finance.settings'))->assertOk()->assertSee('Staff Claims');
        $this->actingAs($aud)->get(route('audit.home'))->assertOk()->assertSee('Audit review');
        $this->actingAs($aud)->get(route('audit.categories'))->assertOk();

        // Audit has no door into Finance and the other way round; an ordinary employee has neither.
        $this->actingAs($aud)->get(route('finance.home'))->assertRedirect();
        $this->actingAs($fin)->get(route('audit.home'))->assertRedirect();
        $this->actingAs($plain)->get(route('finance.home'))->assertRedirect();
        $this->actingAs($plain)->get(route('audit.home'))->assertRedirect();
    }

    public function test_the_finance_list_shows_only_the_users_location_with_the_audit_and_finance_marks(): void
    {
        $west = $this->finance('W1', $this->sowutuom);
        $ash = $this->finance('A1', $this->kumasi);
        $aud = $this->audit('AU1', $this->sowutuom);
        $this->draft($west, ['pv_no' => 'WEST-DRAFT']);
        $sent = $this->sent($west, ['pv_no' => 'WEST-SENT']);
        $this->draft($ash, ['pv_no' => 'ASH-ONLY']);

        $this->actingAs($aud);
        $queried = $this->workflow()->auditQuery($aud, $sent, 'missing_document', 'Receipt missing');

        Livewire::actingAs($west)->test(Vouchers::class)
            ->assertSee('WEST-DRAFT')
            ->assertSee('WEST-SENT')
            ->assertDontSee('ASH-ONLY')
            ->assertSee('Queried')                      // the Audit mark: an icon AND a word
            ->assertSee('Waiting')                      // the Finance mark on a queried voucher
            ->assertSee('Missing supporting document')  // the popover: why
            ->assertSee('Receipt missing')              // and the comment
            ->set('status', Pv::DRAFT)
            ->assertSee('WEST-DRAFT')
            ->assertDontSee('WEST-SENT');

        $this->assertSame(Pv::QUERIED, $queried->status);
    }

    public function test_the_audit_queue_never_lists_a_draft_and_waits_longest_first(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $this->draft($fin, ['pv_no' => 'STILL-DRAFT']);
        Carbon::setTestNow('2026-11-03 09:00:00');
        $this->sent($fin, ['pv_no' => 'OLDER']);
        Carbon::setTestNow('2026-11-06 09:00:00');
        $this->sent($fin, ['pv_no' => 'NEWER']);
        $this->sent($this->finance('F9', $this->kumasi), ['pv_no' => 'KUMASI-ONE']);

        Livewire::actingAs($aud)->test(Queue::class)
            ->assertDontSee('STILL-DRAFT')
            ->assertDontSee('KUMASI-ONE')
            ->assertSeeInOrder(['OLDER', 'NEWER'])
            ->set('status', '')
            ->assertDontSee('STILL-DRAFT');
    }

    public function test_the_form_saves_a_draft_and_sends_one_and_refuses_a_repeated_number(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $type = FinPvType::query()->where('name', 'Stationery')->firstOrFail();
        $fill = fn ($component, string $no) => $component
            ->set('form.pv_date', '2026-11-05')->set('form.pv_no', $no)->set('form.payee_kind', 'district')
            ->set('form.payee_district_id', (string) $this->odorkor->id)->set('form.category_id', (string) $type->category_id)
            ->set('form.type_id', (string) $type->id)->set('form.details', 'Office stationery')->set('form.amount', '300.00');

        $draft = $fill(Livewire::actingAs($fin)->test(VoucherForm::class), 'SCREEN-1')->call('saveDraft')->assertHasNoErrors();
        $pv = Pv::query()->where('pv_no', 'SCREEN-1')->firstOrFail();
        $this->assertSame(Pv::DRAFT, $pv->status);
        $draft->assertRedirect(route('finance.pvs.show', $pv->id));

        $fill(Livewire::actingAs($fin)->test(VoucherForm::class), 'SCREEN-2')->call('saveAndSend')->assertHasNoErrors();
        $this->assertSame(Pv::WITH_AUDIT, Pv::query()->where('pv_no', 'SCREEN-2')->value('status'));

        $fill(Livewire::actingAs($fin)->test(VoucherForm::class), 'screen-1')->call('saveDraft')->assertHasErrors(['form.pv_no']);
        $this->assertSame(2, Pv::query()->count());
    }

    public function test_super_admin_picks_the_location_before_a_new_voucher_can_be_saved(): void
    {
        $super = $this->superAdmin();
        $type = FinPvType::query()->where('name', 'Medical')->firstOrFail();
        $component = Livewire::actingAs($super)->test(VoucherForm::class)
            ->assertSee('Choose a location above')
            ->set('form.pv_no', 'SA-1')->set('form.payee_kind', 'district')->set('form.payee_district_id', (string) $this->odorkor->id)
            ->set('form.category_id', (string) $type->category_id)->set('form.type_id', (string) $type->id)
            ->set('form.details', 'Claim')->set('form.amount', '10.00');

        $component->call('saveDraft')->assertHasErrors(['form.location']);

        $component->set('locationId', (string) $this->kumasi->id)->call('saveDraft')->assertHasNoErrors();
        $this->assertSame($this->ashanti->id, Pv::query()->where('pv_no', 'SA-1')->value('region_id'));
    }

    public function test_a_stale_form_shows_the_conflict_warning_and_overwrites_nothing(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $pv = $this->draft($fin);

        $component = Livewire::actingAs($fin)->test(VoucherForm::class, ['pv' => $pv->id]);
        $this->workflow()->update($this->finance('F2', $this->sowutuom), $pv, $this->pvData(['amount' => '42.00']), 1);

        $component->set('form.amount', '1.00')->call('saveDraft')->assertHasErrors(['conflict'])->assertSee('changed by someone else');
        $this->assertSame(4200, $pv->fresh()->amount);

        $component->call('reload')->assertSet('form.amount', '42.00')->assertSet('version', 2);
    }

    public function test_the_detail_pages_send_review_and_void_through_the_workflow(): void
    {
        $fin = $this->finance('F1', $this->sowutuom);
        $aud = $this->audit('AU1', $this->sowutuom);
        $pv = $this->draft($fin);

        Livewire::actingAs($fin)->test(VoucherShow::class, ['pv' => $pv->id])->call('submit')->assertHasNoErrors();
        $this->assertSame(Pv::WITH_AUDIT, $pv->fresh()->status);

        Livewire::actingAs($aud)->test(AuditVoucherShow::class, ['pv' => $pv->id])
            ->call('openQuery')
            ->set('reasonCode', 'duplicate')->set('comment', '')
            ->call('confirmQuery')->assertHasErrors(['query.comment'])
            ->set('comment', 'Seen on PV-77')->call('confirmQuery')->assertHasNoErrors();
        $this->assertSame(Pv::QUERIED, $pv->fresh()->status);

        Livewire::actingAs($fin)->test(VoucherShow::class, ['pv' => $pv->id])
            ->assertSee('Audit queried this voucher')->assertSee('Seen on PV-77')
            ->call('openVoid')->set('voidReason', '')->call('confirmVoid')->assertHasErrors(['void.reason'])
            ->set('voidReason', 'It is a duplicate')->call('confirmVoid')->assertHasNoErrors();
        $this->assertSame(Pv::VOIDED, $pv->fresh()->status);
    }

    public function test_the_submitter_is_not_offered_the_review_buttons_and_a_forced_call_is_refused(): void
    {
        $both = $this->both('B1', $this->sowutuom);
        $pv = $this->sent($both);

        $component = Livewire::actingAs($both)->test(AuditVoucherShow::class, ['pv' => $pv->id])
            ->assertSee('you cannot review it')
            ->assertDontSee('wire:click="tick"', false)
            ->assertDontSee('wire:click="openQuery"', false);

        $component->call('tick')->assertHasErrors(['action.action']);
        $this->assertSame(Pv::WITH_AUDIT, $pv->fresh()->status);
    }

    public function test_a_voucher_of_another_location_or_a_draft_is_a_404_on_every_screen(): void
    {
        $west = $this->finance('W1', $this->sowutuom);
        $ash = $this->finance('A1', $this->kumasi);
        $aud = $this->audit('AU1', $this->sowutuom);
        $draft = $this->draft($west, ['pv_no' => 'D-1']);
        $sent = $this->sent($west, ['pv_no' => 'S-1']);

        foreach ([
            [VoucherShow::class, $ash, $sent],
            [VoucherForm::class, $ash, $draft],
            [AuditVoucherShow::class, $aud, $draft],                                // a draft is not Audit's
            [AuditVoucherShow::class, $this->audit('AU2', $this->kumasi), $sent],  // another location
        ] as [$class, $user, $pv]) {
            // Livewire turns the abort(404) / ModelNotFound of mount() into a 404 response.
            Livewire::actingAs($user)->test($class, ['pv' => $pv->id])->assertNotFound();
        }
    }
}
