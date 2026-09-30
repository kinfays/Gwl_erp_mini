<?php

namespace Tests\Feature\Letters;

use App\Livewire\Letters\ActiveLetters;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LetterDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Feature\Letters\Concerns\BuildsLettersOrg;
use Tests\TestCase;

class DeliverToAddresseeTest extends TestCase
{
    use BuildsLettersOrg;
    use RefreshDatabase;

    protected Employee $hrSec;

    protected Employee $manager;

    protected Employee $addressee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildLettersOrg();
        $this->hrSec = $this->letterStaff('HR001', $this->accraOffice);
        $this->manager = $this->letterStaff('MGR01', $this->accraOffice, ['manager']);
        $this->addressee = $this->letterStaff('ADR01', $this->accraOffice, ['leave_applicant']);
    }

    /** A letter created by hr and now held, confirmed, by the manager (not the creator). */
    private function heldByManager(string $subject = 'For delivery')
    {
        $letter = $this->createLetter($this->hrSec, ['subject' => $subject]);
        $this->lettersWorkflow()->dispatch($letter, $this->hrSec, $this->manager);
        $this->lettersWorkflow()->confirmHardcopy($letter, $this->manager);

        return $letter;
    }

    private function as(Employee $employee)
    {
        $this->actingAs($this->letterUserOf($employee));

        return Livewire::test(ActiveLetters::class);
    }

    public function test_the_table_has_the_columns_and_foreign_key_rules(): void
    {
        $this->assertTrue(Schema::hasColumns('letter_deliveries', ['letter_id', 'delivered_to_employee_id', 'delivered_to_name', 'delivered_by_id', 'delivered_at', 'note']));

        $letter = $this->heldByManager();
        $this->lettersWorkflow()->deliver($letter, $this->manager, ['delivered_to_employee_id' => $this->addressee->id]);

        // delivered_to_employee_id is nullOnDelete: the record survives if the addressee is removed.
        Employee::withoutEvents(fn () => $this->addressee->delete());
        $delivery = LetterDelivery::query()->sole();
        $this->assertNull($delivery->delivered_to_employee_id);

        // letter_id cascades.
        $letter->delete();
        $this->assertSame(0, LetterDelivery::query()->count());
    }

    public function test_the_current_holder_who_is_not_the_creator_can_deliver_and_it_closes_the_letter(): void
    {
        $letter = $this->heldByManager();
        $this->assertFalse($letter->isClosed());

        $delivery = $this->lettersWorkflow()->deliver($letter, $this->manager, [
            'delivered_to_employee_id' => $this->addressee->id,
            'note' => '  Collected at the front desk  ',
        ]);

        $this->assertTrue($letter->isClosed(), 'the passed model is refreshed');
        $fresh = $letter->fresh();
        $this->assertNotNull($fresh->closed_at);
        $this->assertSame($this->manager->id, $fresh->closed_by_id, 'the closer is the deliverer, not the creator');
        $this->assertNotSame($fresh->created_by_id, $fresh->closed_by_id);

        $this->assertSame($letter->id, $delivery->letter_id);
        $this->assertSame($this->addressee->id, $delivery->delivered_to_employee_id);
        $this->assertNull($delivery->delivered_to_name);
        $this->assertSame($this->manager->id, $delivery->delivered_by_id);
        $this->assertSame('Collected at the front desk', $delivery->note);
        $this->assertTrue($delivery->delivered_at->isToday());
        $this->assertSame('Employee ADR01', $delivery->addresseeName());
        $this->assertSame($delivery->id, $letter->latestDelivery->id);
    }

    public function test_an_outside_party_is_recorded_by_name(): void
    {
        $letter = $this->heldByManager();

        $delivery = $this->lettersWorkflow()->deliver($letter, $this->manager, ['delivered_to_name' => '  Mr Kofi Boateng, Acme Supplies  ']);

        $this->assertNull($delivery->delivered_to_employee_id);
        $this->assertSame('Mr Kofi Boateng, Acme Supplies', $delivery->delivered_to_name);
        $this->assertSame('Mr Kofi Boateng, Acme Supplies', $delivery->addresseeName());
    }

    public function test_the_delivery_time_can_be_recorded_but_not_in_the_future(): void
    {
        $letter = $this->heldByManager();

        try {
            $this->lettersWorkflow()->deliver($letter, $this->manager, ['delivered_to_name' => 'Someone', 'delivered_at' => now()->addHour()->toDateTimeString()]);
            $this->fail('A delivery in the future must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertSame('The delivery time cannot be in the future.', $e->getMessage());
        }

        $this->assertFalse($letter->fresh()->isClosed());

        $delivery = $this->lettersWorkflow()->deliver($letter, $this->manager, ['delivered_to_name' => 'Someone', 'delivered_at' => '2026-09-01 09:30']);
        $this->assertSame('2026-09-01 09:30', $delivery->delivered_at->format('Y-m-d H:i'));
    }

    public function test_exactly_one_addressee_field_is_required(): void
    {
        $letter = $this->heldByManager();

        $cases = [
            'neither' => [[]],
            'blanks' => [['delivered_to_employee_id' => '', 'delivered_to_name' => '   ']],
            'both' => [['delivered_to_employee_id' => $this->addressee->id, 'delivered_to_name' => 'Also a name']],
        ];

        foreach ($cases as $label => [$data]) {
            try {
                $this->lettersWorkflow()->deliver($letter, $this->manager, $data);
                $this->fail("{$label} must be refused.");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Record who received the letter', $e->getMessage(), $label);
            }
        }

        try {
            $this->lettersWorkflow()->deliver($letter, $this->manager, ['delivered_to_employee_id' => 999999]);
            $this->fail('An unknown employee must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertSame('The selected addressee was not found.', $e->getMessage());
        }

        $this->assertFalse($letter->fresh()->isClosed());
        $this->assertSame(0, LetterDelivery::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'deliver_letter')->count());
    }

    public function test_only_the_current_confirmed_holder_of_an_open_letter_can_deliver(): void
    {
        $letter = $this->heldByManager();
        $data = ['delivered_to_name' => 'Someone'];

        // The creator no longer holds it; a stranger never did.
        foreach ([$this->hrSec, $this->letterStaff('SEC02', $this->accraOffice)] as $notHolder) {
            try {
                $this->lettersWorkflow()->deliver($letter, $notHolder, $data);
                $this->fail('A non-holder must not deliver.');
            } catch (\RuntimeException $e) {
                $this->assertSame('Only the current holder of this letter can record its delivery.', $e->getMessage());
            }
        }

        // A recipient who has not confirmed the hardcopy yet.
        $incoming = $this->createLetter($this->hrSec, ['subject' => 'Not confirmed']);
        $this->lettersWorkflow()->dispatch($incoming, $this->hrSec, $this->manager);
        try {
            $this->lettersWorkflow()->deliver($incoming, $this->manager, $data);
            $this->fail('An unconfirmed recipient must not deliver.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Confirm hardcopy receipt before delivering this letter.', $e->getMessage());
        }

        // A letter that is already closed, and one the holder has dispatched onward.
        $this->lettersWorkflow()->deliver($letter, $this->manager, $data);
        try {
            $this->lettersWorkflow()->deliver($letter, $this->manager, $data);
            $this->fail('A closed letter cannot be delivered again.');
        } catch (\RuntimeException $e) {
            $this->assertSame('This letter is already closed.', $e->getMessage());
        }

        $sentOn = $this->heldByManager('Sent on');
        $this->lettersWorkflow()->dispatch($sentOn, $this->manager, $this->hrSec);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only the current holder');
        $this->lettersWorkflow()->deliver($sentOn, $this->manager, $data);
    }

    public function test_delivery_is_audited_and_a_failed_delivery_leaves_no_trace(): void
    {
        $letter = $this->heldByManager();

        try {
            $this->lettersWorkflow()->deliver($letter, $this->hrSec, ['delivered_to_name' => 'Someone']);
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, LetterDelivery::query()->count());
        $this->assertFalse($letter->fresh()->isClosed());

        $delivery = $this->lettersWorkflow()->deliver($letter, $this->manager, ['delivered_to_employee_id' => $this->addressee->id, 'note' => 'Signed for']);

        $audit = AuditLog::query()->where('action', 'deliver_letter')->sole();
        $this->assertSame('letters', $audit->module);
        $this->assertSame('mail_letters', $audit->target_type);
        $this->assertSame($letter->id, $audit->target_id);
        $this->assertSame($delivery->id, $audit->new_values['delivery_id']);
        $this->assertSame($this->addressee->id, $audit->new_values['delivered_to_employee_id']);
        $this->assertSame($this->manager->id, $audit->new_values['delivered_by_id']);
        $this->assertSame(['note' => 'Signed for'], $audit->metadata);
    }

    public function test_a_delivered_letter_shows_under_closed_and_the_creator_can_still_reopen_it(): void
    {
        $letter = $this->heldByManager('Delivered one');
        $this->lettersWorkflow()->deliver($letter, $this->manager, ['delivered_to_employee_id' => $this->addressee->id]);

        $this->as($this->manager)->assertDontSee('Delivered one')->call('setTab', 'closed')->assertSee('Delivered one');

        // Closing a delivered letter by hand stays with the creator, and so does reopening it.
        $this->lettersWorkflow()->reopen($letter, $this->hrSec);
        $this->assertFalse($letter->fresh()->isClosed());
        $this->assertTrue($this->lettersWorkflow()->canDispatch($letter, $this->manager), 'the holder carries on');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only the creator can reopen');
        $this->lettersWorkflow()->reopen($letter, $this->manager);
    }

    // ---- the drawer ---------------------------------------------------------------------------------------

    public function test_the_deliver_tab_is_offered_only_to_the_holder(): void
    {
        $letter = $this->heldByManager();

        $this->as($this->manager)->call('openLetter', $letter->id)->assertSee('Deliver');
        $this->as($this->hrSec)->call('openLetter', $letter->id)->assertDontSee('Deliver to addressee')->assertDontSeeHtml("'deliver')");

        // Not until the hardcopy is confirmed.
        $incoming = $this->createLetter($this->hrSec);
        $this->lettersWorkflow()->dispatch($incoming, $this->hrSec, $this->manager);
        $this->as($this->manager)->call('openLetter', $incoming->id, true)->assertDontSeeHtml("'deliver')");
    }

    public function test_the_holder_records_a_delivery_from_the_drawer(): void
    {
        $letter = $this->heldByManager('Drawer delivery');

        $this->as($this->manager)
            ->call('openLetter', $letter->id)
            ->set('detailTab', 'deliver')
            ->assertSee('Deliver to addressee')
            ->assertSee('Record delivery and close')
            ->assertSee('Employee ADR01 · Registry · Accra Regional Office')
            ->set('deliverEmployeeId', $this->addressee->id)
            ->set('deliverNote', 'Signed at reception')
            ->call('deliverLetter')
            ->assertHasNoErrors()
            ->assertDispatched('toast', type: 'success', message: 'Delivered to Employee ADR01. Letter closed.')
            ->assertSet('tab', 'closed')
            ->assertSet('deliverEmployeeId', '')
            ->assertSet('deliverNote', '')
            ->assertSet('detailTab', 'remarks')
            ->assertSee('Delivered to')
            ->assertSee('Signed at reception');

        $letter->refresh();
        $this->assertTrue($letter->isClosed());
        $this->assertSame($this->manager->id, $letter->closed_by_id);
    }

    public function test_an_outside_party_and_a_time_can_be_entered_in_the_drawer(): void
    {
        $letter = $this->heldByManager();

        $this->as($this->manager)
            ->call('openLetter', $letter->id)
            ->set('detailTab', 'deliver')
            ->set('deliverMode', 'name')
            ->assertSee('Received by (name)')
            ->set('deliverName', 'Ama Mensah (Acme)')
            ->set('deliverAt', '2026-09-01T09:30')
            ->call('deliverLetter')
            ->assertHasNoErrors();

        $delivery = LetterDelivery::query()->sole();
        $this->assertSame('Ama Mensah (Acme)', $delivery->delivered_to_name);
        $this->assertNull($delivery->delivered_to_employee_id);
        $this->assertSame('2026-09-01 09:30', $delivery->delivered_at->format('Y-m-d H:i'));
    }

    public function test_the_drawer_asks_for_the_addressee_for_the_chosen_mode(): void
    {
        $letter = $this->heldByManager();

        $component = $this->as($this->manager)
            ->call('openLetter', $letter->id)
            ->set('detailTab', 'deliver')
            ->call('deliverLetter')
            ->assertHasErrors(['deliverEmployeeId'])
            ->set('deliverMode', 'name')
            ->call('deliverLetter')
            ->assertHasErrors(['deliverName']);

        $this->assertSame(0, LetterDelivery::query()->count());

        // Switching mode does not smuggle the other field through.
        $component->set('deliverName', 'Typed name')->set('deliverEmployeeId', $this->addressee->id)->call('deliverLetter');
        $this->assertNull(LetterDelivery::query()->sole()->delivered_to_employee_id);
    }

    public function test_a_stale_deliver_click_shows_the_rule_as_a_toast(): void
    {
        $letter = $this->heldByManager();

        $component = $this->as($this->manager)
            ->call('openLetter', $letter->id)
            ->set('detailTab', 'deliver')
            ->set('deliverEmployeeId', $this->addressee->id);

        // The letter is sent on from another tab.
        $this->lettersWorkflow()->dispatch($letter, $this->manager, $this->hrSec);

        $component->call('deliverLetter')
            ->assertDispatched('toast', type: 'error', message: 'Only the current holder of this letter can record its delivery.');

        $this->assertSame(0, LetterDelivery::query()->count());
    }

    public function test_someone_who_cannot_see_the_letter_cannot_deliver_it(): void
    {
        $letter = $this->heldByManager();
        $stranger = $this->letterStaff('SEC02', $this->accraOffice);

        $this->as($stranger)->set('selectedLetterId', $letter->id)->set('deliverEmployeeId', $this->addressee->id)->call('deliverLetter')->assertNotFound();

        $this->assertSame(0, LetterDelivery::query()->count());
        $this->assertFalse($letter->fresh()->isClosed());
    }
}
