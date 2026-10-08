<?php

namespace Tests\Feature\HealthSafety;

use App\Models\HsIncident;
use App\Models\User;
use App\Notifications\GeneralDatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

class NotificationsTest extends HealthSafetyTestCase
{
    protected User $reporter;

    protected User $officer;

    protected User $officerElsewhere;

    protected User $districtManager;

    protected User $otherDistrictManager;

    protected User $chief;

    protected User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reporter = $this->reporter('100001');
        $this->reporter->forceFill(['full_name' => 'Efua Mensah'])->save();

        $this->officer = $this->officer('200001', $this->accraWest, $this->odorkor);
        $this->officerElsewhere = $this->officer('200002', $this->ashanti, $this->kumasi);
        $this->districtManager = $this->districtManager('200003', $this->sowutuom);
        $this->otherDistrictManager = $this->districtManager('200004', $this->odorkor);
        $this->chief = $this->chiefManager('200005');
        $this->manager = $this->hsManager('200006');
    }

    /** @return Collection<int, GeneralDatabaseNotification> */
    private function notices(User $user, ?string $type = null): Collection
    {
        return Notification::sent($user, GeneralDatabaseNotification::class)
            ->filter(fn ($notice) => $type === null || ($notice->toArray($user)['type'] ?? null) === $type)
            ->values();
    }

    private function mailed(User $user, string $type): bool
    {
        return $this->notices($user, $type)->contains(fn ($notice) => in_array('mail', $notice->via($user), true));
    }

    public function test_every_new_report_tells_the_regions_officer_and_the_districts_manager_on_the_bell_only(): void
    {
        $incident = $this->incident($this->reporter, ['incident_type' => 'near_miss']);

        foreach ([$this->officer, $this->districtManager] as $recipient) {
            $this->assertCount(1, $this->notices($recipient, 'hs_incident_reported'), "{$recipient->staff_id} should be told");
            $this->assertFalse($this->mailed($recipient, 'hs_incident_reported'), 'an ordinary report is not mailed');
        }

        // Nobody outside the region or district is told, and the management layer is left out of ordinary reports.
        foreach ([$this->officerElsewhere, $this->otherDistrictManager, $this->chief, $this->manager, $this->reporter] as $nobody) {
            $this->assertCount(0, $this->notices($nobody, 'hs_incident_reported'), "{$nobody->staff_id} should not be told");
        }

        $this->assertStringContainsString($incident->reference, $this->notices($this->officer)->first()->toArray($this->officer)['message']);
    }

    public function test_an_injury_an_environmental_report_or_an_urgent_one_is_also_mailed_to_the_escalation_group(): void
    {
        foreach ([['incident_type' => 'injury'], ['incident_type' => 'environmental'], ['incident_type' => 'near_miss', 'is_urgent' => true]] as $index => $overrides) {
            Notification::fake();
            $this->incident($this->reporter('10010'.$index), $overrides);

            foreach ([$this->officer, $this->chief, $this->manager] as $escalated) {
                $this->assertTrue($this->mailed($escalated, 'hs_incident_reported'), "{$escalated->staff_id} should be mailed for ".json_encode($overrides));
            }

            // The district manager still only gets the bell.
            $this->assertCount(1, $this->notices($this->districtManager, 'hs_incident_reported'));
            $this->assertFalse($this->mailed($this->districtManager, 'hs_incident_reported'));

            $this->assertCount(0, $this->notices($this->officerElsewhere), 'the other region is not told');
        }
    }

    public function test_the_notices_never_name_a_confidential_reporter(): void
    {
        $this->incident($this->reporter, ['incident_type' => 'injury', 'is_confidential' => true, 'description' => 'Efua Mensah fell.']);

        foreach ([$this->officer, $this->districtManager, $this->chief, $this->manager] as $recipient) {
            foreach ($this->notices($recipient) as $notice) {
                $data = $notice->toArray($recipient);
                $this->assertStringNotContainsString('Efua', $data['title'].$data['message']);
            }
        }
    }

    public function test_a_high_or_critical_severity_tells_the_chief_manager_and_the_manager_by_mail(): void
    {
        $incident = $this->incident($this->reporter);
        Notification::fake();

        $this->workflow()->triage($incident, $this->officer, ['severity' => 'low']);
        $this->assertCount(0, $this->notices($this->chief, 'hs_incident_severity'));

        $this->workflow()->triage($incident, $this->officer, ['severity' => 'high']);

        foreach ([$this->chief, $this->manager] as $recipient) {
            $this->assertTrue($this->mailed($recipient, 'hs_incident_severity'));
        }

        $this->assertCount(0, $this->notices($this->officerElsewhere, 'hs_incident_severity'));

        // Raising it again to Critical from High is not a fresh alarm.
        $this->workflow()->triage($incident, $this->officer, ['severity' => 'critical']);
        $this->assertCount(1, $this->notices($this->chief, 'hs_incident_severity'));
    }

    public function test_sending_for_approval_tells_the_approvers_and_closing_tells_the_reporter(): void
    {
        $incident = $this->triaged($this->reporter, $this->officer, 'high');
        Notification::fake();

        $pending = $this->workflow()->sendForApproval($incident, $this->officer, 'We repaired the lamp and retrained the team.');

        foreach ([$this->chief, $this->manager] as $approver) {
            $this->assertCount(1, $this->notices($approver, 'hs_incident_approval'));
        }
        $this->assertCount(0, $this->notices($this->reporter), 'the reporter is told when it is closed, not before');

        $this->workflow()->approveAndClose($pending, $this->chief);

        $told = $this->notices($this->reporter, 'hs_incident_closed');
        $this->assertCount(1, $told);
        $this->assertStringContainsString('We repaired the lamp and retrained the team.', $told->first()->toArray($this->reporter)['message']);
    }

    public function test_cancelling_tells_the_reporter(): void
    {
        $incident = $this->incident($this->reporter);
        Notification::fake();

        $this->workflow()->cancel($incident, $this->officer, 'Duplicate of an earlier report.');

        $told = $this->notices($this->reporter, 'hs_incident_cancelled');
        $this->assertCount(1, $told);
        $this->assertStringContainsString('Duplicate of an earlier report.', $told->first()->toArray($this->reporter)['message']);
    }

    public function test_nobody_is_told_of_the_outcome_of_a_report_recorded_for_someone_with_no_login(): void
    {
        $incident = $this->incident($this->districtManager, ['on_behalf' => true, 'behalf_name' => 'Ama Casual']);
        Notification::fake();

        $this->workflow()->triage($incident, $this->officer, ['severity' => 'low']);
        $this->workflow()->close($incident, $this->officer, 'Done.');

        $this->assertCount(0, $this->notices($this->districtManager, 'hs_incident_closed'));
        $this->assertSame(HsIncident::STATUS_CLOSED, $incident->fresh()->status);
    }

    public function test_assigning_an_action_tells_the_assignee(): void
    {
        $incident = $this->triaged($this->reporter, $this->officer, 'low');
        $kofi = $this->userWithRoles('300001', ['employee']);
        Notification::fake();

        $this->workflow()->createAction($incident, $this->officer, ['description' => 'Fix the handrail.', 'assigned_to_employee_id' => $kofi->employee->id, 'due_on' => today()->addWeek()->toDateString()]);

        $told = $this->notices($kofi, 'hs_action_assigned');
        $this->assertCount(1, $told);
        $this->assertStringContainsString('Fix the handrail.', $told->first()->toArray($kofi)['message']);
    }

    public function test_the_notice_links_to_the_incident(): void
    {
        $incident = $this->incident($this->reporter);

        $this->assertSame(route('health_safety.incidents.show', $incident), $this->notices($this->officer)->first()->toArray($this->officer)['url']);
    }
}
