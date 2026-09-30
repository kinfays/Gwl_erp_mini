<?php

namespace Tests\Feature\Letters;

use App\Livewire\Letters\Notifications;
use App\Livewire\Letters\Transmittals;
use App\Models\Employee;
use App\Models\LetterDispatchBatch;
use App\Models\LetterNotification;
use App\Models\ModuleAccess;
use App\Models\Role;
use App\Models\RoutingHistory;
use App\Support\ErpNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Letters\Concerns\BuildsLettersOrg;
use Tests\TestCase;

class TransmittalPageTest extends TestCase
{
    use BuildsLettersOrg;
    use RefreshDatabase;

    protected Employee $hrSec;

    protected Employee $cmSec;

    protected Employee $matSec;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildLettersOrg();
        $this->hrSec = $this->letterStaff('HR001', $this->accraOffice);
        $this->cmSec = $this->letterStaff('CM001', $this->accraOffice);
        $this->matSec = $this->letterStaff('MAT01', $this->accraOffice);
    }

    private function sendBatch(int $count, string $prefix = 'Batch', ?Employee $to = null): LetterDispatchBatch
    {
        $letters = $this->createLetters($this->hrSec, $count, $prefix);

        return $this->lettersWorkflow()->dispatchBatch($this->hrSec, $to ?? $this->cmSec, $letters->pluck('id')->all(), "{$prefix} note");
    }

    private function open(Employee $employee, string $component = Transmittals::class)
    {
        $this->actingAs($this->letterUserOf($employee));

        return Livewire::test($component);
    }

    // ---- incoming -----------------------------------------------------------------------------------------

    public function test_incoming_groups_pending_hops_by_transmittal_with_individual_letters_last(): void
    {
        $first = $this->sendBatch(2, 'Alpha');
        $second = $this->sendBatch(1, 'Beta');
        $single = $this->createLetter($this->hrSec, ['subject' => 'Gamma single']);
        $this->lettersWorkflow()->dispatch($single, $this->hrSec, $this->cmSec);
        $forMat = $this->sendBatch(1, 'Delta', $this->matSec); // not for cm

        $component = $this->open($this->cmSec)
            ->assertSee($first->batch_no)
            ->assertSee($second->batch_no)
            ->assertSee('Individual letters')
            ->assertSee('Alpha 1')->assertSee('Alpha 2')->assertSee('Beta 1')->assertSee('Gamma single')
            ->assertSee('Alpha note')
            ->assertSee('Confirm all (2)')
            ->assertDontSee($forMat->batch_no)
            ->assertDontSee('Delta 1');

        $groups = $component->viewData('groups');
        $this->assertSame([(string) $second->id, (string) $first->id, 'individual'], $groups->pluck('key')->all(), 'newest transmittal first, individual last');
    }

    public function test_incoming_is_empty_when_nothing_waits(): void
    {
        $this->open($this->cmSec)->assertSee('Nothing is waiting for your confirmation.');
    }

    public function test_confirm_all_confirms_the_whole_transmittal_and_it_leaves_the_list(): void
    {
        $batch = $this->sendBatch(3, 'Alpha');
        $other = $this->sendBatch(1, 'Beta');

        $this->open($this->cmSec)
            ->call('confirmGroup', (string) $batch->id)
            ->assertDispatched('toast', type: 'success', message: 'Confirmed hardcopy receipt for 3 letters.')
            ->assertDontSee($batch->batch_no)
            ->assertSee($other->batch_no);

        $batch->refresh();
        $this->assertSame(3, $batch->confirmed_count);
        $this->assertNotNull($batch->completed_at);
        $this->assertSame(0, $other->fresh()->confirmed_count);
    }

    public function test_confirm_ticked_leaves_the_unticked_lines_pending(): void
    {
        $batch = $this->sendBatch(3, 'Alpha');
        $hops = $batch->routingHistories()->orderBy('id')->get();

        $component = $this->open($this->cmSec)
            ->call('toggleLine', $hops[2]->id)
            ->assertSee('Confirm ticked (2)')
            ->call('confirmGroup', (string) $batch->id, true)
            ->assertDispatched('toast', type: 'success', message: 'Confirmed hardcopy receipt for 2 letters.');

        $batch->refresh();
        $this->assertSame(2, $batch->confirmed_count);
        $this->assertNull($batch->completed_at);
        $this->assertTrue($hops[0]->fresh()->received_confirm);
        $this->assertFalse($hops[2]->fresh()->received_confirm);

        // The remaining line is still listed, still unticked; ticking it again and confirming finishes the batch.
        $component->assertSee('Alpha 3')->assertSee('Confirm ticked (0)')
            ->call('toggleLine', $hops[2]->id)
            ->call('confirmGroup', (string) $batch->id, true);

        $this->assertNotNull($batch->fresh()->completed_at);
    }

    public function test_confirm_ticked_with_nothing_ticked_says_so(): void
    {
        $batch = $this->sendBatch(2);

        $component = $this->open($this->cmSec);
        foreach ($batch->routingHistories as $hop) {
            $component->call('toggleLine', $hop->id);
        }

        $component->call('confirmGroup', (string) $batch->id, true)
            ->assertDispatched('toast', type: 'error', message: 'Tick at least one letter to confirm.');

        $this->assertSame(0, $batch->fresh()->confirmed_count);
    }

    public function test_confirm_all_on_the_individual_group_confirms_only_unbatched_hops(): void
    {
        $batch = $this->sendBatch(2, 'Alpha');
        $singles = $this->createLetters($this->hrSec, 2, 'Single');
        foreach ($singles as $letter) {
            $this->lettersWorkflow()->dispatch($letter, $this->hrSec, $this->cmSec);
        }

        $this->open($this->cmSec)
            ->assertSee('Confirm all (2)')
            ->call('confirmGroup', 'individual')
            ->assertDispatched('toast', type: 'success', message: 'Confirmed hardcopy receipt for 2 letters.');

        $this->assertSame(0, $batch->fresh()->confirmed_count);
        $this->assertSame(2, RoutingHistory::query()->whereNull('batch_id')->where('received_confirm', true)->count());
    }

    public function test_a_transmittal_addressed_to_someone_else_cannot_be_confirmed(): void
    {
        $forMat = $this->sendBatch(2, 'Delta', $this->matSec);

        $this->open($this->cmSec)
            ->call('confirmGroup', (string) $forMat->id)
            ->assertDispatched('toast', type: 'error', message: 'That transmittal is not addressed to you.');

        $this->assertSame(0, RoutingHistory::query()->where('received_confirm', true)->count());
        $this->assertSame(0, $forMat->fresh()->confirmed_count);

        // The sender cannot confirm their own hand-over either.
        $this->open($this->hrSec)
            ->call('confirmGroup', (string) $forMat->id)
            ->assertDispatched('toast', type: 'error', message: 'That transmittal is not addressed to you.');
    }

    public function test_a_transmittal_page_can_be_focused_from_a_link(): void
    {
        $batch = $this->sendBatch(1);

        $this->actingAs($this->letterUserOf($this->cmSec));
        Livewire::withQueryParams(['tab' => 'incoming', 'batch' => $batch->id])
            ->test(Transmittals::class)
            ->assertSet('focusBatch', $batch->id)
            ->assertSet('tab', 'incoming')
            ->assertSeeHtml('is-focused');

        $this->actingAs($this->letterUserOf($this->hrSec));
        Livewire::withQueryParams(['tab' => 'sent', 'batch' => $batch->id])
            ->test(Transmittals::class)
            ->assertSet('tab', 'sent')
            ->assertSeeHtml('is-focused');
    }

    // ---- sent ---------------------------------------------------------------------------------------------

    public function test_sent_shows_progress_and_per_line_status(): void
    {
        $batch = $this->sendBatch(2, 'Alpha');
        $hops = $batch->routingHistories()->orderBy('id')->get();
        $this->lettersWorkflow()->confirmHardcopies($this->cmSec, [$hops[0]->letter_id], $batch);
        $other = $this->sendBatch(1, 'Beta');

        $this->open($this->hrSec)
            ->call('setTab', 'sent')
            ->assertSee($batch->batch_no)
            ->assertSee('1 of 2 confirmed')
            ->assertSee($other->batch_no)
            ->assertSee('0 of 1 confirmed')
            ->assertSee('Confirmed')
            ->assertSee('Awaiting hardcopy')
            ->assertSee('waiting today')
            ->assertSee('Print sheet')
            ->assertSeeHtml(route('letters.transmittals.sheet', $batch));

        $this->lettersWorkflow()->confirmHardcopies($this->cmSec, [$hops[1]->letter_id], $batch);

        $this->open($this->hrSec)->call('setTab', 'sent')->assertSee('2 of 2 confirmed');
    }

    public function test_sent_lists_only_the_actors_own_transmittals(): void
    {
        $mine = $this->sendBatch(1, 'Alpha');

        $this->open($this->cmSec)->call('setTab', 'sent')
            ->assertDontSee($mine->batch_no)
            ->assertSee('You have not sent any transmittals.');
    }

    // ---- access -------------------------------------------------------------------------------------------

    public function test_the_page_renders_for_a_letters_user_and_needs_the_module(): void
    {
        $this->sendBatch(2);

        $this->actingAs($this->letterUserOf($this->cmSec))
            ->get(route('letters.transmittals'))
            ->assertOk()
            ->assertSee('Transmittals');

        $stranger = $this->letterStaff('STR01', $this->accraOffice, ['leave_applicant']);
        $this->actingAs($this->letterUserOf($stranger))->get(route('letters.transmittals'))->assertRedirect('/dashboard');

        $this->open($stranger)->assertForbidden(); // the component re-checks, as the layers are meant to
    }

    public function test_guests_are_sent_to_login(): void
    {
        $batch = $this->sendBatch(1);

        $this->get(route('letters.transmittals'))->assertRedirect(route('login'));
        $this->get(route('letters.transmittals.sheet', $batch))->assertRedirect(route('login'));
    }

    // ---- sheet --------------------------------------------------------------------------------------------

    public function test_the_sheet_is_a_pdf_for_the_sender_the_recipient_and_super_admin(): void
    {
        $batch = $this->sendBatch(2);
        $admin = $this->letterStaff('ADM01', $this->accraOffice, ['super_admin']);
        // The route middleware has no super_admin bypass: the seeded super_admin role carries module access.
        ModuleAccess::query()->create(['role_id' => Role::query()->where('name', 'super_admin')->value('id'), 'module' => 'letters', 'can_access' => true]);

        foreach ([$this->hrSec, $this->cmSec, $admin] as $reader) {
            $response = $this->actingAs($this->letterUserOf($reader))->get(route('letters.transmittals.sheet', $batch));

            $response->assertOk();
            $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
            $this->assertStringContainsString($batch->batch_no.'.pdf', $response->headers->get('Content-Disposition'));
            $this->assertStringStartsWith('%PDF', $response->getContent());
        }
    }

    public function test_the_sheet_is_forbidden_to_everyone_else(): void
    {
        $batch = $this->sendBatch(2);
        $viewer = $this->letterStaff('VIEW01', $this->accraOffice, ['letters_viewer']);
        $noModule = $this->letterStaff('NOM01', $this->accraOffice, ['leave_applicant']);

        foreach ([$this->matSec, $viewer] as $reader) {
            $this->actingAs($this->letterUserOf($reader))
                ->get(route('letters.transmittals.sheet', $batch))
                ->assertForbidden();
        }

        $this->actingAs($this->letterUserOf($noModule))->get(route('letters.transmittals.sheet', $batch))->assertRedirect('/dashboard');

        $this->actingAs($this->letterUserOf($this->hrSec))->get('/letters/transmittals/999999/sheet')->assertNotFound();
    }

    public function test_the_sheet_lists_every_letter_the_parties_and_the_signature_lines(): void
    {
        $batch = $this->sendBatch(2, 'Sheet');
        $batch->load(['fromSecretariat.department', 'toSecretariat.department', 'routingHistories.letter.memoSender']);

        $html = view('letters.exports.transmittal-sheet', ['batch' => $batch])->render();

        $this->assertStringContainsString($batch->batch_no, $html);
        $this->assertStringContainsString('Employee HR001', $html);
        $this->assertStringContainsString('Employee CM001', $html);
        $this->assertStringContainsString('Sheet note', $html);
        foreach ($batch->routingHistories as $hop) {
            $this->assertStringContainsString($hop->letter->sn_number, $html);
            $this->assertStringContainsString($hop->letter->subject, $html);
            $this->assertStringContainsString('REF/001', $html);
        }
        $this->assertStringContainsString('Dispatched by', $html);
        $this->assertStringContainsString('Received by', $html);
    }

    // ---- notifications and sidebar -----------------------------------------------------------------------

    public function test_a_batch_notification_opens_the_transmittal_and_shows_the_batch_number(): void
    {
        $batch = $this->sendBatch(3);
        $single = $this->createLetter($this->hrSec);
        $this->lettersWorkflow()->dispatch($single, $this->hrSec, $this->cmSec);

        $batchNotification = LetterNotification::query()->where('batch_id', $batch->id)->sole();
        $singleNotification = LetterNotification::query()->where('letter_id', $single->id)->sole();

        $component = $this->open($this->cmSec, Notifications::class)
            ->call('toggle')
            ->assertSee($batch->batch_no)
            ->assertSee('Letters dispatched to you')
            ->assertSee($single->sn_number);

        $component->call('openNotification', $batchNotification->id)
            ->assertRedirect(route('letters.transmittals', ['tab' => 'incoming', 'batch' => $batch->id]));
        $this->assertTrue($batchNotification->fresh()->is_read);

        $component->call('openNotification', $singleNotification->id)
            ->assertRedirect(route('letters.active', ['letter' => $single->id, 'prompt' => 1]));
    }

    public function test_only_the_recipient_can_open_a_batch_notification(): void
    {
        $batch = $this->sendBatch(1);
        $notification = LetterNotification::query()->where('batch_id', $batch->id)->sole();

        $this->open($this->matSec, Notifications::class)
            ->call('openNotification', $notification->id)
            ->assertNotFound();

        $this->assertFalse($notification->fresh()->is_read);
    }

    private function transmittalsSidebarItem(Employee $employee): array
    {
        $user = $this->letterUserOf($employee)->fresh();
        $sidebar = app(ErpNavigation::class)->build($user, 'letters')['sidebar'];

        return collect($sidebar)->firstWhere('label', 'Transmittals');
    }

    public function test_the_sidebar_has_a_transmittals_entry_with_the_pending_incoming_count(): void
    {
        $item = $this->transmittalsSidebarItem($this->cmSec);
        $this->assertSame(route('letters.transmittals'), $item['url']);
        $this->assertSame(0, $item['badge']);

        $batch = $this->sendBatch(3);
        $single = $this->createLetter($this->hrSec);
        $this->lettersWorkflow()->dispatch($single, $this->hrSec, $this->cmSec);

        $this->assertSame(4, $this->transmittalsSidebarItem($this->cmSec)['badge']);
        $this->assertSame(0, $this->transmittalsSidebarItem($this->hrSec)['badge'], 'the sender has nothing to confirm');

        $this->lettersWorkflow()->confirmHardcopies($this->cmSec, $batch->routingHistories->pluck('letter_id')->all(), $batch);
        $this->assertSame(1, $this->transmittalsSidebarItem($this->cmSec)['badge']);
    }

    public function test_the_count_is_rendered_in_the_layout_only_when_something_is_pending(): void
    {
        $this->actingAs($this->letterUserOf($this->cmSec))
            ->get(route('letters.active'))
            ->assertOk()
            ->assertDontSee('side-count');

        $this->sendBatch(3);

        $this->actingAs($this->letterUserOf($this->cmSec))
            ->get(route('letters.active'))
            ->assertOk()
            ->assertSeeHtml('side-label side-count')
            ->assertSee('Transmittals');
    }
}
