<?php

namespace Tests\Feature\HealthSafety;

use App\Livewire\HealthSafety\Actions;
use App\Livewire\HealthSafety\IncidentShow;
use App\Models\HsIncident;
use App\Models\HsIncidentAction;
use App\Services\HealthSafety\IncidentVisibility;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * The three small Phase 1 follow-ups: a completion note on actions, "closed by / approved by" on the incident screen, and
 * the optional rule that whoever sent an incident for approval cannot also approve it.
 */
class PhaseOneFollowUpsTest extends HealthSafetyTestCase
{
    // ---------------------------------------------------------------- a) completion note

    public function test_an_assignee_writes_a_completion_note_on_the_actions_list_and_it_shows_on_the_incident(): void
    {
        $officer = $this->officer();
        $incident = $this->triaged($this->reporter(), $officer, 'low');
        $kofi = $this->userWithRoles('300001', ['employee']);
        $action = $this->workflow()->createAction($incident, $officer, ['description' => 'Fix the handrail.', 'assigned_to_employee_id' => $kofi->employee->id, 'due_on' => today()->addWeek()->toDateString()]);

        Livewire::actingAs($kofi)->test(Actions::class)
            ->set("notes.{$action->id}", 'Replaced the rail and re-fixed the bolts.')
            ->call('complete', $action->id)
            ->assertHasNoErrors()
            ->assertSee('Replaced the rail and re-fixed the bolts.');

        $action = $action->fresh();
        $this->assertSame(HsIncidentAction::STATUS_DONE, $action->status);
        $this->assertSame('Replaced the rail and re-fixed the bolts.', $action->completion_note);

        Livewire::actingAs($officer)->test(IncidentShow::class, ['incident' => $incident])->assertSee('Replaced the rail and re-fixed the bolts.');
    }

    public function test_the_note_is_optional_and_limited_in_length(): void
    {
        $officer = $this->officer();
        $incident = $this->triaged($this->reporter(), $officer, 'low');
        $kofi = $this->userWithRoles('300001', ['employee']);
        $one = $this->workflow()->createAction($incident, $officer, ['description' => 'One.', 'assigned_to_employee_id' => $kofi->employee->id, 'due_on' => today()->addWeek()->toDateString()]);
        $two = $this->workflow()->createAction($incident, $officer, ['description' => 'Two.', 'assigned_to_employee_id' => $kofi->employee->id, 'due_on' => today()->addWeek()->toDateString()]);

        Livewire::actingAs($kofi)->test(Actions::class)->call('complete', $one->id)->assertHasNoErrors();
        $this->assertNull($one->fresh()->completion_note);

        Livewire::actingAs($kofi)->test(Actions::class)->set("notes.{$two->id}", str_repeat('x', 1001))->call('complete', $two->id)->assertHasErrors(["notes.{$two->id}"]);
        $this->assertSame(HsIncidentAction::STATUS_OPEN, $two->fresh()->status);
    }

    public function test_the_officer_can_add_a_note_when_marking_an_action_done_on_the_incident_screen(): void
    {
        $officer = $this->officer();
        $incident = $this->triaged($this->reporter(), $officer, 'low');
        $action = $this->workflow()->createAction($incident, $officer, ['description' => 'Paint the line.', 'assigned_to_employee_id' => $officer->employee->id, 'due_on' => today()->addWeek()->toDateString()]);

        Livewire::actingAs($officer)->test(IncidentShow::class, ['incident' => $incident])
            ->set("completionNotes.{$action->id}", 'Two coats applied.')
            ->call('completeAction', $action->id)
            ->assertHasNoErrors()
            ->assertSee('Two coats applied.');

        $this->assertSame('Two coats applied.', $action->fresh()->completion_note);
    }

    // ---------------------------------------------------------------- b) closed by / approved by

    public function test_closed_by_and_approved_by_are_shown_to_those_entitled_to_the_file(): void
    {
        $reporter = $this->reporter();
        $officer = $this->officer();
        $officer->forceFill(['full_name' => 'Officer Ama'])->save();
        $chief = $this->chiefManager();
        $chief->forceFill(['full_name' => 'Chief Kojo'])->save();

        $incident = $this->triaged($reporter, $officer, 'high');
        $this->workflow()->sendForApproval($incident, $officer, 'Lamp replaced.');
        $this->workflow()->approveAndClose($incident, $chief);

        Livewire::actingAs($officer)->test(IncidentShow::class, ['incident' => $incident])
            ->assertSee('Closed')->assertSee('by Chief Kojo')->assertDontSee('approved by Chief Kojo');

        // A low incident the officer closes themselves shows only "closed by".
        $low = $this->triaged($this->reporter('100002'), $officer, 'low');
        $this->workflow()->close($low, $officer, 'Done.');
        Livewire::actingAs($officer)->test(IncidentShow::class, ['incident' => $low])->assertSee('by Officer Ama');

        // The reporter sees the outcome and the date, not who closed it.
        Livewire::actingAs($reporter)->test(IncidentShow::class, ['incident' => $incident])
            ->assertSee('Lamp replaced.')->assertDontSee('Chief Kojo')->assertDontSee('Officer Ama');
        $this->actingAs($reporter)->get(route('health_safety.incidents.print', $incident))->assertDontSee('Chief Kojo');
    }

    public function test_when_the_closer_and_the_approver_differ_both_are_named(): void
    {
        $officer = $this->officer();
        $officer->forceFill(['full_name' => 'Officer Ama'])->save();
        $manager = $this->hsManager();
        $manager->forceFill(['full_name' => 'Manager Esi'])->save();
        $chief = $this->chiefManager();
        $chief->forceFill(['full_name' => 'Chief Kojo'])->save();

        $incident = $this->triaged($this->reporter(), $officer, 'critical');
        $this->workflow()->sendForApproval($incident, $manager, 'Resolved.');
        $closed = $this->workflow()->approveAndClose($incident, $chief);

        // closed_by and approved_by are the same person here (the approver closes), so the approver is named once.
        $closure = app(IncidentVisibility::class)->closureFor($officer, $closed);
        $this->assertSame('Chief Kojo', $closure['closed_by']);
        $this->assertNull($closure['approved_by']);

        // Forcing them apart shows both.
        $closed->forceFill(['closed_by' => $officer->id])->save();
        $closure = app(IncidentVisibility::class)->closureFor($officer, $closed->fresh());
        $this->assertSame('Officer Ama', $closure['closed_by']);
        $this->assertSame('Chief Kojo', $closure['approved_by']);
    }

    public function test_a_confidential_reporter_who_also_closed_the_incident_is_not_named(): void
    {
        $officer = $this->officer();
        $officer->forceFill(['full_name' => 'Officer Ama'])->save();
        $manager = $this->districtManager('200003', $this->sowutuom);

        // The officer filed the report themselves, in confidence, then closed it.
        $incident = $this->incident($officer, ['is_confidential' => true]);
        $this->workflow()->triage($incident, $officer, ['severity' => 'low']);
        $this->workflow()->close($incident, $officer, 'Sorted.');

        $visibility = app(IncidentVisibility::class);
        $this->assertSame('Officer Ama', $visibility->closureFor($officer, $incident->fresh())['closed_by']);

        // The district manager is entitled to the file but not to know who reported it, so the closer shows as the reporter.
        $this->assertSame('Reporter', $visibility->closureFor($manager, $incident->fresh())['closed_by']);
        Livewire::actingAs($manager)->test(IncidentShow::class, ['incident' => $incident])->assertDontSee('Officer Ama');
        $this->actingAs($manager)->get(route('health_safety.incidents.print', $incident))->assertDontSee('Officer Ama');
    }

    // ---------------------------------------------------------------- c) second approver

    public function test_by_default_the_person_who_sent_it_for_approval_may_approve_it(): void
    {
        $this->assertFalse(config('gwl.hs_require_second_approver'));

        $manager = $this->hsManager();
        $incident = $this->triaged($this->reporter(), $manager, 'high');
        $this->workflow()->sendForApproval($incident, $manager, 'Done.');

        $this->assertSame(HsIncident::STATUS_CLOSED, $this->workflow()->approveAndClose($incident, $manager)->status);
    }

    public function test_with_the_rule_on_the_sender_cannot_approve_but_someone_else_can(): void
    {
        config(['gwl.hs_require_second_approver' => true]);

        $manager = $this->hsManager();
        $chief = $this->chiefManager();
        $incident = $this->triaged($this->reporter(), $manager, 'high');
        $pending = $this->workflow()->sendForApproval($incident, $manager, 'Done.');

        try {
            $this->workflow()->approveAndClose($pending, $manager);
            $this->fail('The person who sent it for approval must not also approve it.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('someone else has to approve', $exception->errors()['status'][0]);
        }

        $this->assertSame(HsIncident::STATUS_PENDING_CLOSURE, $incident->fresh()->status);

        $this->assertSame(HsIncident::STATUS_CLOSED, $this->workflow()->approveAndClose($pending, $chief)->status);
    }

    public function test_the_rule_applies_to_super_admin_too(): void
    {
        config(['gwl.hs_require_second_approver' => true]);

        $super = $this->superAdmin();
        $officer = $this->officer();
        $incident = $this->triaged($this->reporter(), $officer, 'critical');
        $pending = $this->workflow()->sendForApproval($incident, $super, 'Done.');

        $this->expectException(ValidationException::class);

        $this->workflow()->approveAndClose($pending, $super);
    }

    public function test_the_rule_looks_at_the_latest_time_it_was_sent_for_approval(): void
    {
        config(['gwl.hs_require_second_approver' => true]);

        $manager = $this->hsManager();
        $officer = $this->officer();
        $chief = $this->chiefManager();
        $incident = $this->triaged($this->reporter(), $officer, 'high');

        // The manager sent it, the chief sent it back, the officer then sent it again: the manager may now approve.
        $this->workflow()->sendForApproval($incident, $manager, 'First try.');
        $this->workflow()->returnForRework($incident, $chief, 'Needs more.');
        $pending = $this->workflow()->sendForApproval($incident, $officer, 'Second try.');

        $this->assertSame(HsIncident::STATUS_CLOSED, $this->workflow()->approveAndClose($pending, $manager)->status);
    }

    public function test_the_screen_hides_the_approve_button_from_the_sender_when_the_rule_is_on(): void
    {
        $manager = $this->hsManager();
        $incident = $this->triaged($this->reporter(), $manager, 'high');
        $this->workflow()->sendForApproval($incident, $manager, 'Done.');

        Livewire::actingAs($manager)->test(IncidentShow::class, ['incident' => $incident])->assertSee('Approve and close');

        config(['gwl.hs_require_second_approver' => true]);

        Livewire::actingAs($manager)->test(IncidentShow::class, ['incident' => $incident])
            ->assertDontSee('Approve and close')
            ->assertSee('someone else has to approve it')
            ->assertSee('Return for rework');

        // And a replayed call is refused by the service all the same.
        Livewire::actingAs($manager)->test(IncidentShow::class, ['incident' => $incident])->call('approve')->assertHasErrors(['status']);
        $this->assertSame(HsIncident::STATUS_PENDING_CLOSURE, $incident->fresh()->status);

        Livewire::actingAs($this->chiefManager())->test(IncidentShow::class, ['incident' => $incident])->assertSee('Approve and close');
    }
}
