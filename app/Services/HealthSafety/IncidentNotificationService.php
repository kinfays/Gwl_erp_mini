<?php

namespace App\Services\HealthSafety;

use App\Models\Employee;
use App\Models\HsIncident;
use App\Models\HsIncidentAction;
use App\Models\User;
use App\Notifications\GeneralDatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Who is told what (design section 3.3). Every notice goes to the general bell as a database notification; mail is added
 * only where it matters (an Injury, Environmental or urgent report, and High / Critical severity).
 *
 * The notices never name the reporter, so a confidential report cannot leak through the bell or an email.
 */
class IncidentNotificationService
{
    /** Types that are mailed at once to the officers, the regional chief manager and the Health & Safety Manager. */
    public const URGENT_TYPES = [HsIncident::TYPE_INJURY, HsIncident::TYPE_ENVIRONMENTAL];

    public function newReport(HsIncident $incident): void
    {
        $urgent = $incident->is_urgent || in_array($incident->incident_type, self::URGENT_TYPES, true);

        $officers = $this->usersWithRole(['hs_officer'], $incident->region_id);
        $districtManagers = $incident->district_id
            ? $this->usersWithRole(['district_manager'], null, $incident->district_id)
            : collect();

        // Mailed on top of the bell: the officers, the regional chief manager and the Health & Safety Manager.
        $escalation = $urgent
            ? $officers->merge($this->usersWithRole(['regional_chief_manager'], $incident->region_id))->merge($this->usersWithRole(['hs_manager']))
            : collect();

        $everyone = $officers->merge($districtManagers)->merge($escalation)->unique('id');
        $mailed = $escalation->pluck('id')->all();

        $title = $urgent ? 'Urgent: new incident report' : 'New incident report';
        $message = sprintf('%s reported: %s at %s on %s.', $incident->reference, $incident->typeLabel(), $incident->placeLabel() ?: 'an unspecified place', $incident->occurred_on->format('d M Y'));

        foreach ($everyone as $user) {
            $this->send($user, $title, $message, route('health_safety.incidents.show', $incident), [
                'type' => 'hs_incident_reported',
                'incident_id' => $incident->id,
            ], in_array($user->id, $mailed, true));
        }
    }

    public function severityRaised(HsIncident $incident): void
    {
        $recipients = $this->usersWithRole(['regional_chief_manager'], $incident->region_id)
            ->merge($this->usersWithRole(['hs_manager']))
            ->unique('id');

        $message = sprintf('%s (%s) was rated %s severity.', $incident->reference, $incident->typeLabel(), strtolower(HsIncident::SEVERITIES[$incident->severity] ?? (string) $incident->severity));

        foreach ($recipients as $user) {
            $this->send($user, 'High-severity incident', $message, route('health_safety.incidents.show', $incident), [
                'type' => 'hs_incident_severity',
                'incident_id' => $incident->id,
            ], true);
        }
    }

    public function approvalRequested(HsIncident $incident): void
    {
        $approvers = $this->usersWithRole(['regional_chief_manager'], $incident->region_id)
            ->merge($this->usersWithRole(['hs_manager']))
            ->unique('id');

        foreach ($approvers as $user) {
            $this->send($user, 'Incident awaiting your approval', $incident->reference.' is ready to be closed and needs your approval.', route('health_safety.incidents.show', $incident), [
                'type' => 'hs_incident_approval',
                'incident_id' => $incident->id,
            ]);
        }
    }

    /** The reporter hears the outcome in plain words. Nobody is told for a report recorded for someone with no login. */
    public function closed(HsIncident $incident): void
    {
        $reporter = $this->reporterOf($incident);

        if (! $reporter) {
            return;
        }

        $message = trim('Your report '.$incident->reference.' has been closed. '.($incident->closure_note ?? ''));

        $this->send($reporter, 'Your incident report was closed', $message, route('health_safety.incidents.show', $incident), [
            'type' => 'hs_incident_closed',
            'incident_id' => $incident->id,
        ]);
    }

    public function cancelled(HsIncident $incident): void
    {
        $reporter = $this->reporterOf($incident);

        if (! $reporter) {
            return;
        }

        $message = trim('Your report '.$incident->reference.' was cancelled. '.($incident->cancel_reason ?? ''));

        $this->send($reporter, 'Your incident report was cancelled', $message, route('health_safety.incidents.show', $incident), [
            'type' => 'hs_incident_cancelled',
            'incident_id' => $incident->id,
        ]);
    }

    public function actionAssigned(HsIncidentAction $action): void
    {
        $action->loadMissing(['incident', 'assignee']);
        $user = $this->userOfEmployee($action->assignee);

        if (! $user) {
            return;
        }

        $this->send(
            $user,
            'Safety action assigned to you',
            sprintf('%s: %s (due %s).', $action->incident->reference, $action->description, $action->due_on->format('d M Y')),
            route('health_safety.actions'),
            ['type' => 'hs_action_assigned', 'incident_id' => $action->incident_id, 'action_id' => $action->id],
        );
    }

    /** The reporter's login, or null when nobody with a login should be told. */
    protected function reporterOf(HsIncident $incident): ?User
    {
        return $incident->reported_by_user_id
            ? User::query()->active()->find($incident->reported_by_user_id)
            : null;
    }

    public function userOfEmployee(?Employee $employee): ?User
    {
        if (! $employee) {
            return null;
        }

        return User::query()->active()
            ->where(fn ($query) => $query->where('employee_id', $employee->id)->orWhere('staff_id', $employee->staff_id))
            ->first();
    }

    /**
     * Active users holding any of the roles, in a region or district when given (by where their employee record says
     * they work). super_admin accounts are never real recipients here.
     *
     * @param  list<string>  $roles
     * @return Collection<int, User>
     */
    public function usersWithRole(array $roles, ?int $regionId = null, ?int $districtId = null): Collection
    {
        return User::query()
            ->active()
            ->visibleInErp()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', $roles))
            ->when($regionId, fn ($query) => $query->whereHas('employee', fn ($employee) => $employee->where('region_id', $regionId)))
            ->when($districtId, fn ($query) => $query->whereHas('employee', fn ($employee) => $employee->where('district_id', $districtId)))
            ->get();
    }

    protected function send(User $user, string $title, string $message, string $url, array $meta, bool $mail = false): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        $user->notify(new GeneralDatabaseNotification($title, $message, $url, 'health_safety', $meta, $mail));
    }
}
