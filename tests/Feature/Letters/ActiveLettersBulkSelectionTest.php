<?php

namespace Tests\Feature\Letters;

use App\Livewire\Letters\ActiveLetters;
use App\Models\Employee;
use App\Models\LetterDispatchBatch;
use App\Models\LetterNotification;
use App\Models\RoutingHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Letters\Concerns\BuildsLettersOrg;
use Tests\TestCase;

class ActiveLettersBulkSelectionTest extends TestCase
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

    private function as(Employee $employee)
    {
        $this->actingAs($this->letterUserOf($employee));

        return Livewire::test(ActiveLetters::class);
    }

    private function toastMessages($component): array
    {
        return collect($component->effects['dispatches'] ?? [])
            ->where('name', 'toast')
            ->map(fn (array $event) => $event['params']['message'] ?? null)
            ->values()
            ->all();
    }

    // ---- selection lifetime -------------------------------------------------------------------------------

    public function test_selection_is_cleared_when_the_tab_or_any_filter_changes(): void
    {
        $letters = $this->createLetters($this->hrSec, 2);
        $ids = $letters->pluck('id')->all();

        $this->as($this->hrSec)
            ->set('selected', $ids)->call('setTab', 'closed')->assertSet('selected', [])
            ->set('selected', $ids)->call('setTab', 'active')->assertSet('selected', [])
            ->set('selected', $ids)->call('setQuickFilter', 'ready')->assertSet('selected', [])
            ->set('selected', $ids)->set('quickFilter', '')->assertSet('selected', [])
            ->set('selected', $ids)->set('typeFilter', 'External')->assertSet('selected', [])
            ->set('selected', $ids)->set('search', 'Letter')->assertSet('selected', []);
    }

    public function test_selection_is_cleared_when_the_page_changes(): void
    {
        $letters = $this->createLetters($this->hrSec, 17); // 15 per page

        $this->as($this->hrSec)
            ->set('selected', $letters->take(2)->pluck('id')->all())
            ->call('gotoPage', 2)
            ->assertSet('selected', [])
            ->set('selected', [$letters[0]->id])
            ->call('previousPage')
            ->assertSet('selected', []);
    }

    public function test_the_quick_filter_toggles_off_when_clicked_again(): void
    {
        $this->as($this->hrSec)
            ->call('setQuickFilter', 'ready')->assertSet('quickFilter', 'ready')
            ->call('setQuickFilter', 'ready')->assertSet('quickFilter', '')
            ->call('setQuickFilter', 'awaiting')->assertSet('quickFilter', 'awaiting')
            ->call('setQuickFilter', 'nonsense')->assertSet('quickFilter', '');
    }

    // ---- select all on page -------------------------------------------------------------------------------

    public function test_the_header_checkbox_selects_only_the_rows_the_actor_can_act_on_and_toggles_back(): void
    {
        $ready = $this->createLetters($this->cmSec, 2, 'Held');
        $awaiting = $this->createLetter($this->hrSec, ['subject' => 'Coming in']);
        $this->lettersWorkflow()->dispatch($awaiting, $this->hrSec, $this->cmSec);
        $sentOn = $this->createLetter($this->cmSec, ['subject' => 'Sent on']);
        $this->lettersWorkflow()->dispatch($sentOn, $this->cmSec, $this->matSec);

        $expected = [...$ready->pluck('id')->all(), $awaiting->id];
        sort($expected);

        $component = $this->as($this->cmSec)->call('togglePage');
        $selected = $component->get('selected');
        sort($selected);
        $this->assertSame($expected, $selected);
        $this->assertNotContains($sentOn->id, $selected);
        $component->assertViewHas('allOnPageSelected', true);

        $component->call('togglePage')->assertSet('selected', [])->assertViewHas('allOnPageSelected', false);
    }

    public function test_without_letters_forward_only_awaiting_rows_can_be_selected(): void
    {
        $viewer = $this->letterStaff('VIEW01', $this->accraOffice, ['letters_viewer']);
        $held = $this->createLetter($this->hrSec);
        $this->lettersWorkflow()->dispatch($held, $this->hrSec, $viewer);
        $this->lettersWorkflow()->confirmHardcopy($held, $viewer);
        $awaiting = $this->createLetter($this->hrSec, ['subject' => 'Awaiting viewer']);
        $this->lettersWorkflow()->dispatch($awaiting, $this->hrSec, $viewer);

        $this->as($viewer)
            ->call('togglePage')
            ->assertSet('selected', [$awaiting->id])
            ->assertDontSee('Ready to dispatch');
    }

    public function test_rows_show_a_tickbox_only_when_they_can_be_acted_on(): void
    {
        $mine = $this->createLetter($this->hrSec, ['subject' => 'Mine to send']);
        $gone = $this->createLetter($this->hrSec, ['subject' => 'Already gone']);
        $this->lettersWorkflow()->dispatch($gone, $this->hrSec, $this->cmSec);

        $this->as($this->hrSec)
            ->assertSeeHtml('aria-label="Select '.$mine->sn_number.'"')
            ->assertSee($gone->sn_number.' cannot be selected');
    }

    // ---- quick filters (SQL) ------------------------------------------------------------------------------

    /** A cm desk with every situation: awaiting, held, sent on, closed, back-and-forth in the same second. */
    private function buildBusyDesk(): array
    {
        $awaiting = $this->createLetter($this->hrSec, ['subject' => 'Awaiting one']);
        $this->lettersWorkflow()->dispatch($awaiting, $this->hrSec, $this->cmSec);

        $held = $this->createLetter($this->cmSec, ['subject' => 'Held one']);

        $sentOn = $this->createLetter($this->cmSec, ['subject' => 'Sent on one']);
        $this->lettersWorkflow()->dispatch($sentOn, $this->cmSec, $this->matSec);

        $closed = $this->createLetter($this->cmSec, ['subject' => 'Closed one']);
        $this->lettersWorkflow()->close($closed, $this->cmSec);

        // cm -> mat -> cm again: cm has two logs for it, written within the same second.
        $roundTrip = $this->createLetter($this->cmSec, ['subject' => 'Round trip one']);
        $this->lettersWorkflow()->dispatch($roundTrip, $this->cmSec, $this->matSec);
        $this->lettersWorkflow()->confirmHardcopy($roundTrip, $this->matSec);
        $this->lettersWorkflow()->dispatch($roundTrip, $this->matSec, $this->cmSec);
        $this->lettersWorkflow()->confirmHardcopy($roundTrip, $this->cmSec);

        return compact('awaiting', 'held', 'sentOn', 'closed', 'roundTrip');
    }

    public function test_the_awaiting_filter_lists_only_letters_with_a_hop_waiting_for_the_actor(): void
    {
        $l = $this->buildBusyDesk();

        $this->as($this->cmSec)
            ->call('setQuickFilter', 'awaiting')
            ->assertViewHas('letters', fn ($page) => $page->pluck('id')->all() === [$l['awaiting']->id])
            ->assertSee('Awaiting one')
            ->assertDontSee('Held one');
    }

    public function test_the_ready_filter_lists_only_letters_the_actor_holds_and_may_dispatch(): void
    {
        $l = $this->buildBusyDesk();

        $ids = $this->as($this->cmSec)
            ->call('setQuickFilter', 'ready')
            ->viewData('letters')
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        $expected = collect([$l['held']->id, $l['roundTrip']->id])->sort()->values()->all();
        $this->assertSame($expected, $ids);
    }

    public function test_the_sql_quick_filters_agree_with_the_desk_state_rules_for_every_letter(): void
    {
        $this->buildBusyDesk();

        foreach ([$this->cmSec, $this->matSec, $this->hrSec] as $actor) {
            $all = $this->lettersWorkflow()->visibleLettersQuery($actor)->get();
            $desk = $this->lettersWorkflow()->deskState($all, $actor);

            $ready = $this->lettersWorkflow()->whereReadyToDispatch($this->lettersWorkflow()->visibleLettersQuery($actor), $actor)->pluck('id')->sort()->values()->all();
            $awaiting = $this->lettersWorkflow()->whereAwaitingConfirmation($this->lettersWorkflow()->visibleLettersQuery($actor), $actor)->pluck('id')->sort()->values()->all();

            $this->assertSame($desk->filter(fn ($s) => $s['canDispatch'])->keys()->sort()->values()->all(), $ready, "ready for {$actor->staff_id}");
            $this->assertSame($desk->filter(fn ($s) => $s['pendingRoute'] !== null)->keys()->sort()->values()->all(), $awaiting, "awaiting for {$actor->staff_id}");
        }
    }

    // ---- confirm selected ---------------------------------------------------------------------------------

    public function test_confirm_selected_acts_on_the_awaiting_rows_and_reports_the_skipped_ones(): void
    {
        $awaiting = $this->createLetters($this->hrSec, 2, 'Incoming');
        foreach ($awaiting as $letter) {
            $this->lettersWorkflow()->dispatch($letter, $this->hrSec, $this->cmSec);
        }
        $held = $this->createLetter($this->cmSec, ['subject' => 'Already mine']);

        $component = $this->as($this->cmSec)
            ->set('selected', [...$awaiting->pluck('id')->all(), $held->id])
            ->assertSee('3 selected')
            ->assertSee('Confirm hardcopies (2)')
            ->call('confirmSelected')
            ->assertSet('selected', [])
            ->assertDispatched('toast', type: 'success', message: 'Confirmed hardcopy receipt for 2 letters. 1 selected letter skipped (not awaiting your confirmation).');

        $this->assertSame(0, $this->lettersWorkflow()->pendingIncomingCount($this->cmSec));
        $this->assertSame(2, RoutingHistory::query()->where('received_confirm', true)->count());
        $this->assertNull(RoutingHistory::query()->where('letter_id', $held->id)->first());
        $component->assertDontSee('Confirm hardcopies (');
    }

    public function test_confirm_selected_with_nothing_awaiting_shows_a_message(): void
    {
        $held = $this->createLetter($this->cmSec);

        $this->as($this->cmSec)
            ->set('selected', [$held->id])
            ->call('confirmSelected')
            ->assertDispatched('toast', type: 'error', message: 'None of the selected letters is awaiting your confirmation.');
    }

    public function test_confirm_selected_never_touches_letters_the_actor_cannot_see(): void
    {
        $forMat = $this->createLetter($this->hrSec, ['subject' => 'For materials']);
        $this->lettersWorkflow()->dispatch($forMat, $this->hrSec, $this->matSec);

        $this->as($this->cmSec)
            ->set('selected', [$forMat->id])
            ->call('confirmSelected')
            ->assertDispatched('toast', type: 'error', message: 'None of the selected letters is awaiting your confirmation.');

        $this->assertNotNull($this->lettersWorkflow()->pendingIncomingRoute($forMat, $this->matSec));
    }

    public function test_confirming_needs_no_forward_permission(): void
    {
        $viewer = $this->letterStaff('VIEW01', $this->accraOffice, ['letters_viewer']);
        $letter = $this->createLetter($this->hrSec);
        $this->lettersWorkflow()->dispatch($letter, $this->hrSec, $viewer);

        $this->as($viewer)
            ->set('selected', [$letter->id])
            ->call('confirmSelected')
            ->assertDispatched('toast', type: 'success', message: 'Confirmed hardcopy receipt for 1 letter.');
    }

    // ---- dispatch selected --------------------------------------------------------------------------------

    public function test_dispatch_selected_creates_one_transmittal_from_the_ready_rows_and_reports_the_skipped(): void
    {
        $ready = $this->createLetters($this->hrSec, 2, 'Ready');
        $gone = $this->createLetter($this->hrSec, ['subject' => 'Already gone']);
        $this->lettersWorkflow()->dispatch($gone, $this->hrSec, $this->matSec);

        $component = $this->as($this->hrSec)
            ->set('selected', [...$ready->pluck('id')->all(), $gone->id])
            ->assertSee('Dispatch selected (2)')
            ->call('openBulkDispatch')
            ->assertSet('bulkDispatchOpen', true)
            ->assertSet('bulkLetterIds', $ready->pluck('id')->all())
            ->assertSet('bulkSkipped', 1)
            ->assertSee('Dispatch 2 letters')
            ->set('bulkDispatchToId', $this->cmSec->id)
            ->set('bulkNote', 'Morning mail')
            ->call('dispatchSelected')
            ->assertHasNoErrors()
            ->assertSet('selected', [])
            ->assertSet('bulkDispatchOpen', false);

        $batch = LetterDispatchBatch::query()->sole();
        $this->assertSame(2, $batch->letters_count);
        $this->assertSame('Morning mail', $batch->note);
        $this->assertSame($this->cmSec->id, $batch->to_secretariat_id);
        $component
            ->assertSet('lastBatchId', $batch->id)
            ->assertSet('lastBatchNo', $batch->batch_no)
            ->assertDispatched('toast', type: 'success', message: "Transmittal {$batch->batch_no} created: 2 letters dispatched to {$this->cmSec->full_name}. 1 selected letter skipped (not ready to dispatch).")
            ->assertSee('Transmittal '.$batch->batch_no.' created')
            ->assertSeeHtml(route('letters.transmittals.sheet', $batch));

        $this->assertSame(1, LetterNotification::query()->where('batch_id', $batch->id)->count());
    }

    public function test_the_drawer_lists_the_letters_being_dispatched(): void
    {
        $letters = $this->createLetters($this->hrSec, 2, 'Listed');

        $this->as($this->hrSec)
            ->set('selected', $letters->pluck('id')->all())
            ->call('openBulkDispatch')
            ->assertSee($letters[0]->sn_number)
            ->assertSee('Listed 1')
            ->assertSee('Listed 2')
            ->assertSee('2 letters in one transmittal');
    }

    public function test_dispatch_selected_needs_a_recipient(): void
    {
        $letters = $this->createLetters($this->hrSec, 2);

        $this->as($this->hrSec)
            ->set('selected', $letters->pluck('id')->all())
            ->call('openBulkDispatch')
            ->call('dispatchSelected')
            ->assertHasErrors(['bulkDispatchToId'])
            ->assertSet('bulkDispatchOpen', true);

        $this->assertSame(0, LetterDispatchBatch::query()->count());
    }

    public function test_a_tampered_selection_of_letters_the_actor_cannot_see_is_ignored(): void
    {
        $someoneElses = $this->createLetter($this->cmSec, ['subject' => 'Confidential']);

        $this->as($this->hrSec)
            ->set('selected', [$someoneElses->id])
            ->call('openBulkDispatch')
            ->assertSet('bulkDispatchOpen', false)
            ->assertDispatched('toast', type: 'error', message: 'None of the selected letters can be dispatched by you right now.');

        $this->assertSame(0, RoutingHistory::query()->count());
    }

    public function test_a_tampered_drawer_list_cannot_dispatch_letters_the_actor_cannot_see(): void
    {
        $mine = $this->createLetter($this->hrSec);
        $someoneElses = $this->createLetter($this->cmSec, ['subject' => 'Confidential']);

        $component = $this->as($this->hrSec)
            ->set('selected', [$mine->id])
            ->call('openBulkDispatch')
            ->set('bulkLetterIds', [$mine->id, $someoneElses->id]) // the client edits the frozen list
            ->set('bulkDispatchToId', $this->matSec->id)
            ->call('dispatchSelected');

        $component->assertSet('bulkDispatchOpen', false);
        $messages = $this->toastMessages($component);
        $this->assertCount(1, $messages);
        $this->assertStringNotContainsString($someoneElses->sn_number, $messages[0]);
        $this->assertStringNotContainsString('Confidential', $messages[0]);

        $this->assertSame(0, LetterDispatchBatch::query()->count());
        $this->assertSame(0, RoutingHistory::query()->count());
        $this->assertSame('Received', $this->lettersWorkflow()->currentLog($someoneElses, $this->cmSec)->status);
    }

    public function test_a_letter_that_changed_while_the_drawer_was_open_refuses_the_whole_dispatch(): void
    {
        $letters = $this->createLetters($this->hrSec, 3, 'Stale');

        $component = $this->as($this->hrSec)
            ->set('selected', $letters->pluck('id')->all())
            ->call('openBulkDispatch')
            ->set('bulkDispatchToId', $this->cmSec->id);

        // Another tab dispatches one of them meanwhile.
        $this->lettersWorkflow()->dispatch($letters[1], $this->hrSec, $this->matSec);
        $hopsBefore = RoutingHistory::query()->count();

        $component->call('dispatchSelected')->assertSet('bulkDispatchOpen', false);

        $messages = $this->toastMessages($component);
        $this->assertCount(1, $messages);
        $this->assertStringContainsString($letters[1]->sn_number, $messages[0]);
        $this->assertStringContainsString('Nothing was dispatched', $messages[0]);
        $this->assertSame(0, LetterDispatchBatch::query()->count());
        $this->assertSame($hopsBefore, RoutingHistory::query()->count());

        // The selection is refreshed to what can still be acted on.
        $remaining = $component->get('selected');
        sort($remaining);
        $this->assertSame([$letters[0]->id, $letters[2]->id], $remaining);
    }

    public function test_a_selection_over_the_size_limit_is_refused(): void
    {
        config(['gwl.letters_max_batch_size' => 2]);
        $letters = $this->createLetters($this->hrSec, 3);

        $component = $this->as($this->hrSec)
            ->set('selected', $letters->pluck('id')->all())
            ->call('openBulkDispatch')
            ->assertSee('A transmittal can hold at most 2 letters')
            ->set('bulkDispatchToId', $this->cmSec->id)
            ->call('dispatchSelected');

        $this->assertStringContainsString('at most 2 letters', $this->toastMessages($component)[0]);
        $this->assertSame(0, LetterDispatchBatch::query()->count());
    }

    public function test_dispatching_needs_the_forward_permission(): void
    {
        $viewer = $this->letterStaff('VIEW01', $this->accraOffice, ['letters_viewer']);
        $letter = $this->createLetter($this->hrSec);
        $this->lettersWorkflow()->dispatch($letter, $this->hrSec, $viewer);
        $this->lettersWorkflow()->confirmHardcopy($letter, $viewer);

        $this->as($viewer)
            ->set('selected', [$letter->id])
            ->call('openBulkDispatch')
            ->assertForbidden();

        $this->as($viewer)
            ->set('selected', [$letter->id])
            ->set('bulkLetterIds', [$letter->id])
            ->set('bulkDispatchToId', $this->hrSec->id)
            ->call('dispatchSelected')
            ->assertForbidden();

        $this->assertSame(0, LetterDispatchBatch::query()->count());
    }

    public function test_dispatch_selected_can_be_cancelled_without_losing_the_selection(): void
    {
        $letters = $this->createLetters($this->hrSec, 2);

        $this->as($this->hrSec)
            ->set('selected', $letters->pluck('id')->all())
            ->call('openBulkDispatch')
            ->set('bulkDispatchToId', $this->cmSec->id)
            ->call('closeBulkDispatch')
            ->assertSet('bulkDispatchOpen', false)
            ->assertSet('bulkLetterIds', [])
            ->assertSet('bulkDispatchToId', '')
            ->assertCount('selected', 2);
    }

    public function test_the_bulk_bar_and_drawer_only_appear_when_something_is_selected(): void
    {
        $letter = $this->createLetter($this->hrSec);

        $this->as($this->hrSec)
            ->assertDontSee('Dispatch selected')
            ->set('selected', [$letter->id])
            ->assertSee('1 selected')
            ->assertSee('Dispatch selected (1)')
            ->assertSee('Confirm hardcopies (0)')
            ->call('clearSelection')
            ->assertDontSee('Dispatch selected');
    }
}
