<?php

namespace Tests\Feature\HealthSafety;

use App\Livewire\HealthSafety\Actions;
use App\Livewire\HealthSafety\IncidentShow;
use App\Models\AuditLog;
use App\Models\HsIncident;
use App\Models\HsIncidentAction;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

class IncidentWorkflowTest extends HealthSafetyTestCase
{
    public function test_a_new_report_starts_as_reported_with_a_timeline_row_and_an_audit_entry(): void
    {
        $incident = $this->incident($this->reporter());

        $this->assertSame(HsIncident::STATUS_REPORTED, $incident->status);
        $this->assertNull($incident->severity);
        $this->assertSame([[null, 'reported']], $incident->statusLogs()->get()->map(fn ($log) => [$log->from_status, $log->to_status])->all());
        $this->assertTrue(AuditLog::query()->where('action', 'health_safety.incident_reported')->where('target_id', $incident->id)->exists());
    }

    public function test_acknowledging_stamps_who_and_when_and_writes_a_status_log(): void
    {
        $officer = $this->officer();
        $incident = $this->incident($this->reporter());

        $incident = $this->workflow()->acknowledge($incident, $officer);

        $this->assertSame(HsIncident::STATUS_ACKNOWLEDGED, $incident->status);
        $this->assertSame($officer->id, $incident->acknowledged_by);
        $this->assertNotNull($incident->acknowledged_at);
        $this->assertSame($officer->id, $incident->owner_user_id);
        $this->assertSame('acknowledged', $incident->statusLogs()->latest('id')->first()->to_status);
        $this->assertTrue(AuditLog::query()->where('action', 'health_safety.incident_acknowledged')->exists());
    }

    public function test_triage_sets_the_severity_and_writes_a_status_log_that_tells_what_changed(): void
    {
        $officer = $this->officer();
        $incident = $this->incident($this->reporter(), ['incident_type' => 'incident']);

        $incident = $this->workflow()->triage($incident, $officer, ['severity' => 'high', 'incident_type' => 'injury']);

        $this->assertSame('high', $incident->severity);
        $this->assertSame('injury', $incident->incident_type);
        $this->assertSame(HsIncident::STATUS_ACKNOWLEDGED, $incident->status, 'triage acknowledges a new report');

        $log = $incident->statusLogs()->latest('id')->first();
        $this->assertSame($officer->id, $log->user_id);
        $this->assertStringContainsString('Severity: not set -> High', $log->note);
        $this->assertStringContainsString('Type: Incident -> Injury', $log->note, 'a reclassification is logged');
        $this->assertTrue(AuditLog::query()->where('action', 'health_safety.incident_triaged')->exists());
    }

    public function test_triage_needs_a_severity_and_the_right_permission_and_region(): void
    {
        $incident = $this->incident($this->reporter());

        try {
            $this->workflow()->triage($incident, $this->officer(), ['severity' => '']);
            $this->fail('A severity is required.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('severity', $exception->errors());
        }

        foreach ([$this->reporter('100009'), $this->districtManager('200003', $this->sowutuom), $this->officer('200050', $this->ashanti, $this->kumasi)] as $outsider) {
            try {
                $this->workflow()->triage($incident, $outsider, ['severity' => 'low']);
                $this->fail('Only an officer of this region may triage.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }

        $this->assertNull($incident->fresh()->severity);
    }

    public function test_the_owner_must_be_someone_who_manages_incidents(): void
    {
        $officer = $this->officer();
        $incident = $this->incident($this->reporter());

        $this->expectException(ValidationException::class);

        $this->workflow()->triage($incident, $officer, ['severity' => 'low', 'owner_user_id' => $this->reporter('100010')->id]);
    }

    public function test_low_and_medium_incidents_are_closed_by_the_officer(): void
    {
        foreach (['low', 'medium'] as $severity) {
            $officer = $this->officer();
            $incident = $this->triaged($this->reporter('1001'.strlen($severity)), $officer, $severity);

            $closed = $this->workflow()->close($incident, $officer, 'Reminded staff to keep the walkway clear.');

            $this->assertSame(HsIncident::STATUS_CLOSED, $closed->status);
            $this->assertSame($officer->id, $closed->closed_by);
            $this->assertNotNull($closed->closed_at);
            $this->assertSame('Reminded staff to keep the walkway clear.', $closed->closure_note);
        }
    }

    public function test_high_and_critical_incidents_stop_at_pending_closure_and_only_an_approver_closes_them(): void
    {
        foreach (['high', 'critical'] as $index => $severity) {
            $officer = $this->officer();
            $chief = $this->chiefManager();
            $incident = $this->triaged($this->reporter('10020'.$index), $officer, $severity);

            // The officer cannot close it directly...
            try {
                $this->workflow()->close($incident, $officer, 'Done.');
                $this->fail('A High incident must not close without approval.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('severity', $exception->errors());
            }

            // ...only send it on.
            $pending = $this->workflow()->sendForApproval($incident, $officer, 'Done, subject to approval.');
            $this->assertSame(HsIncident::STATUS_PENDING_CLOSURE, $pending->status);
            $this->assertNull($pending->closed_at);

            // The officer is not an approver.
            try {
                $this->workflow()->approveAndClose($pending, $officer);
                $this->fail('An officer must not approve their own closure.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }

            // Neither is an ordinary reporter, nor an approver from another region.
            foreach ([$this->reporter('10030'.$index), $this->chiefManager('20007'.$index, $this->ashanti)] as $outsider) {
                try {
                    $this->workflow()->approveAndClose($pending, $outsider);
                    $this->fail('Only an approver in scope may close it.');
                } catch (HttpException $exception) {
                    $this->assertSame(403, $exception->getStatusCode());
                }
            }

            $closed = $this->workflow()->approveAndClose($pending, $chief);
            $this->assertSame(HsIncident::STATUS_CLOSED, $closed->status);
            $this->assertSame($chief->id, $closed->approved_by);
            $this->assertNotNull($closed->approved_at);
        }
    }

    public function test_only_high_and_critical_incidents_can_be_sent_for_approval(): void
    {
        $officer = $this->officer();
        $incident = $this->triaged($this->reporter(), $officer, 'low');

        $this->expectException(ValidationException::class);

        $this->workflow()->sendForApproval($incident, $officer, 'Done.');
    }

    public function test_an_approver_can_return_a_closure_for_rework_with_a_reason(): void
    {
        $officer = $this->officer();
        $chief = $this->chiefManager();
        $incident = $this->triaged($this->reporter(), $officer, 'high');
        $pending = $this->workflow()->sendForApproval($incident, $officer, 'Done.');

        try {
            $this->workflow()->returnForRework($pending, $chief, '  ');
            $this->fail('A reason is required.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }

        $returned = $this->workflow()->returnForRework($pending, $chief, 'Findings are too thin.');

        $this->assertSame(HsIncident::STATUS_INVESTIGATING, $returned->status);
        $this->assertStringContainsString('Findings are too thin.', $returned->statusLogs()->latest('id')->first()->note);
    }

    public function test_an_injury_cannot_close_without_a_root_cause_and_findings(): void
    {
        $officer = $this->officer();
        $incident = $this->triaged($this->reporter(), $officer, 'medium', ['incident_type' => 'injury']);

        try {
            $this->workflow()->close($incident, $officer, 'Closed.');
            $this->fail('An injury needs a root cause and findings first.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('root_cause_category', $exception->errors());
            $this->assertArrayHasKey('findings', $exception->errors());
        }

        $this->assertSame(HsIncident::STATUS_ACKNOWLEDGED, $incident->fresh()->status);

        $this->workflow()->saveInvestigation($incident, $officer, 'human', 'Rushed down wet stairs.');
        $this->assertSame(HsIncident::STATUS_CLOSED, $this->workflow()->close($incident, $officer, 'Closed.')->status);
    }

    public function test_property_damage_environmental_and_incident_need_findings_but_a_near_miss_and_other_do_not(): void
    {
        $officer = $this->officer();

        foreach (['property_damage', 'environmental', 'incident'] as $index => $type) {
            $incident = $this->triaged($this->reporter('10040'.$index), $officer, 'low', ['incident_type' => $type]);

            try {
                $this->workflow()->close($incident, $officer, 'Closed.');
                $this->fail("{$type} needs findings.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('findings', $exception->errors());
            }
        }

        foreach (['near_miss', 'other'] as $index => $type) {
            $incident = $this->triaged($this->reporter('10050'.$index), $officer, 'low', ['incident_type' => $type, 'other_type_text' => 'Odd smell']);

            $this->assertSame(HsIncident::STATUS_CLOSED, $this->workflow()->close($incident, $officer, 'Noted.')->status);
        }
    }

    public function test_closing_needs_a_severity_a_note_and_an_acknowledgement(): void
    {
        $officer = $this->officer();

        $unrated = $this->workflow()->acknowledge($this->incident($this->reporter('100601')), $officer);

        try {
            $this->workflow()->close($unrated, $officer, 'Done.');
            $this->fail('An unrated incident cannot be closed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('severity', $exception->errors());
        }

        $rated = $this->triaged($this->reporter('100602'), $officer, 'low');

        try {
            $this->workflow()->close($rated, $officer, '   ');
            $this->fail('A closure note is required.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('closure_note', $exception->errors());
        }

        $fresh = $this->incident($this->reporter('100603'));

        try {
            $this->workflow()->close($fresh, $officer, 'Done.');
            $this->fail('An unacknowledged report cannot be closed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }
    }

    public function test_open_actions_do_not_block_closure(): void
    {
        $officer = $this->officer();
        $incident = $this->triaged($this->reporter(), $officer, 'low');
        $assignee = $this->employee('300001', 'Kofi Fixer');

        $this->workflow()->createAction($incident, $officer, ['description' => 'Fix the handrail.', 'assigned_to_employee_id' => $assignee->id, 'due_on' => today()->addWeek()->toDateString()]);

        $closed = $this->workflow()->close($incident, $officer, 'Handrail work scheduled.');

        $this->assertSame(HsIncident::STATUS_CLOSED, $closed->status);
        $this->assertSame(HsIncidentAction::STATUS_OPEN, $closed->actions()->first()->status);
    }

    public function test_an_assignee_completes_their_own_action_but_not_anyone_elses(): void
    {
        $officer = $this->officer();
        $incident = $this->triaged($this->reporter(), $officer, 'low');
        $kofi = $this->userWithRoles('300001', ['employee']);
        $ama = $this->userWithRoles('300002', ['employee']);

        $action = $this->workflow()->createAction($incident, $officer, ['description' => 'Fix the handrail.', 'assigned_to_employee_id' => $kofi->employee->id, 'due_on' => today()->addDays(3)->toDateString()]);

        try {
            $this->workflow()->completeAction($action, $ama, 'Not mine.');
            $this->fail('Someone else may not complete it.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $done = $this->workflow()->completeAction($action, $kofi, 'Handrail replaced.');
        $this->assertSame(HsIncidentAction::STATUS_DONE, $done->status);
        $this->assertSame('Handrail replaced.', $done->completion_note);
        $this->assertNotNull($done->completed_on);

        // Verification belongs to the officer, not the assignee.
        try {
            $this->workflow()->verifyAction($done, $kofi);
            $this->fail('An assignee may not verify their own work.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertSame(HsIncidentAction::STATUS_VERIFIED, $this->workflow()->verifyAction($done, $officer)->status);
    }

    public function test_the_actions_screen_lets_an_assignee_without_any_safety_permission_mark_their_own_done(): void
    {
        $officer = $this->officer();
        $incident = $this->triaged($this->reporter(), $officer, 'low');
        $kofi = $this->userWithRoles('300001', ['employee']);

        $action = $this->workflow()->createAction($incident, $officer, ['description' => 'Fix the handrail.', 'assigned_to_employee_id' => $kofi->employee->id, 'due_on' => today()->addDays(3)->toDateString()]);

        Livewire::actingAs($kofi)->test(Actions::class)
            ->assertSee('Fix the handrail.')
            ->call('complete', $action->id)
            ->assertHasNoErrors();

        $this->assertSame(HsIncidentAction::STATUS_DONE, $action->fresh()->status);

        // Someone with no link to the incident does not even see the action, and a replayed call is refused.
        $stranger = $this->userWithRoles('300003', ['employee']);
        $other = $this->workflow()->createAction($incident, $officer, ['description' => 'Repaint the line.', 'assigned_to_employee_id' => $kofi->employee->id, 'due_on' => today()->addDays(3)->toDateString()]);

        Livewire::actingAs($stranger)->test(Actions::class)->assertDontSee('Repaint the line.')->call('complete', $other->id)->assertForbidden();
    }

    public function test_the_due_date_cannot_be_in_the_past_on_the_screen(): void
    {
        $officer = $this->officer();
        $incident = $this->triaged($this->reporter(), $officer, 'low');
        $assignee = $this->employee('300001', 'Kofi Fixer');

        Livewire::actingAs($officer)->test(IncidentShow::class, ['incident' => $incident])
            ->set('newAction', ['description' => 'Late.', 'due_on' => today()->subDay()->toDateString(), 'assigned_to_employee_id' => $assignee->id])
            ->call('createAction')
            ->assertHasErrors(['newAction.due_on']);

        $this->assertSame(0, HsIncidentAction::query()->count());
    }

    public function test_cancel_and_reopen_require_a_reason(): void
    {
        $officer = $this->officer();
        $incident = $this->triaged($this->reporter(), $officer, 'low');

        try {
            $this->workflow()->cancel($incident, $officer, '');
            $this->fail('Cancelling needs a reason.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }

        $cancelled = $this->workflow()->cancel($incident, $officer, 'Duplicate of HS-AW-2026-0001.');
        $this->assertSame(HsIncident::STATUS_CANCELLED, $cancelled->status);
        $this->assertSame('Duplicate of HS-AW-2026-0001.', $cancelled->cancel_reason);

        $closable = $this->triaged($this->reporter('100701'), $officer, 'low');
        $closed = $this->workflow()->close($closable, $officer, 'Done.');

        try {
            $this->workflow()->reopen($closed, $officer, '   ');
            $this->fail('Reopening needs a reason.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }

        $reopened = $this->workflow()->reopen($closed, $officer, 'New evidence.');
        $this->assertSame(HsIncident::STATUS_INVESTIGATING, $reopened->status);
        $this->assertSame(1, $reopened->reopened_count);
        $this->assertNull($reopened->closed_at);
        $this->assertTrue(AuditLog::query()->where('action', 'health_safety.incident_reopened')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'health_safety.incident_cancelled')->exists());
    }

    public function test_states_cannot_be_skipped_or_repeated(): void
    {
        $officer = $this->officer();
        $incident = $this->incident($this->reporter());

        // Reopen only works on a closed incident; cancelled ones stay cancelled.
        foreach ([fn () => $this->workflow()->reopen($incident, $officer, 'Why not.')] as $attempt) {
            try {
                $attempt();
                $this->fail('Only a closed incident can be reopened.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('status', $exception->errors());
            }
        }

        $cancelled = $this->workflow()->cancel($incident, $officer, 'Duplicate.');

        foreach ([
            fn () => $this->workflow()->acknowledge($cancelled, $officer),
            fn () => $this->workflow()->cancel($cancelled, $officer, 'Again.'),
            fn () => $this->workflow()->reopen($cancelled, $officer, 'Again.'),
            fn () => $this->workflow()->triage($cancelled, $officer, ['severity' => 'low']),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A cancelled incident must stay cancelled.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('status', $exception->errors());
            }
        }
    }

    public function test_triage_is_refused_while_waiting_for_approval(): void
    {
        $officer = $this->officer();
        $incident = $this->triaged($this->reporter(), $officer, 'high');
        $pending = $this->workflow()->sendForApproval($incident, $officer, 'Done.');

        // Otherwise lowering the severity would walk a High incident past its approver.
        $this->expectException(ValidationException::class);

        $this->workflow()->triage($pending, $officer, ['severity' => 'low']);
    }

    public function test_the_screen_walks_an_officer_from_a_new_report_to_a_closed_one(): void
    {
        $reporter = $this->reporter();
        $officer = $this->officer();
        $incident = $this->incident($reporter);

        $page = Livewire::actingAs($officer)->test(IncidentShow::class, ['incident' => $incident]);

        $page->call('acknowledge');
        $this->assertSame(HsIncident::STATUS_ACKNOWLEDGED, $incident->fresh()->status);

        $page->set('severity', 'low')->call('saveTriage')->assertHasNoErrors();
        $this->assertSame('low', $incident->fresh()->severity);

        $page->set('closureNote', '')->call('close')->assertHasErrors(['closure_note']);

        $page->set('closureNote', 'Spoke to the team.')->call('close')->assertHasNoErrors();
        $this->assertSame(HsIncident::STATUS_CLOSED, $incident->fresh()->status);

        $page->call('openReason', 'reopen')->set('reason', '')->call('submitReason')->assertHasErrors(['reason']);
        $page->set('reason', 'Reporter disagreed.')->call('submitReason')->assertHasNoErrors();
        $this->assertSame(HsIncident::STATUS_INVESTIGATING, $incident->fresh()->status);
    }

    public function test_a_call_replayed_after_the_incident_left_the_users_reach_is_refused(): void
    {
        $officer = $this->officer();
        $incident = $this->incident($this->reporter());

        $page = Livewire::actingAs($officer)->test(IncidentShow::class, ['incident' => $incident]);

        $incident->update(['region_id' => $this->ashanti->id, 'district_id' => $this->kumasi->id]);

        $page->call('acknowledge')->assertForbidden();
        $this->assertSame(HsIncident::STATUS_REPORTED, $incident->fresh()->status);
    }
}
