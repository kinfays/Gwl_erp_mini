<?php

namespace App\Services\HealthSafety;

use App\Models\Employee;
use App\Models\HsIncident;
use App\Models\HsIncidentAction;
use App\Models\User;
use App\Notifications\GeneralDatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The scheduled alerts (design 8.18 B): due dates on the expiry register, due dates of incident actions, and the chasing of
 * reports and investigations that sit too long. Run by the health-safety:send-alerts command.
 *
 * The rule, the same for every kind: each item has a LIST of thresholds (days before its due date, negative = overdue; for
 * a report, the escalation tiers 1 and 2). An item crosses a threshold once its days-to-due is at or below it. All crossed
 * thresholds that are not yet in hs_alert_log are recorded, but only the MOST URGENT crossed one is announced, so an item
 * first seen 20 days out gets one alert (the 30), never a late "60" followed by a "30". The log key includes the due date,
 * so a renewed date starts the cycle again, and its unique index (claimed with insert-or-ignore BEFORE anything is sent)
 * makes a double send impossible even if two runs overlap.
 *
 * Nobody gets a notification per item. Each recipient gets ONE digest per run: counts by kind, a few examples and a link.
 * It goes to the bell always and to email only when the digest holds a threshold of 7 days or less. Nothing in any text
 * names a person: references, asset codes and counts only, so a confidential reporter cannot leak.
 */
class HealthSafetyAlertService
{
    public const GROUP_EQUIPMENT = 'equipment';
    public const GROUP_ACTIONS = 'actions';
    public const GROUP_INCIDENTS = 'incidents';
    /** Just the "not acknowledged" tiers: what the hourly run does. */
    public const GROUP_ACKNOWLEDGEMENT = 'acknowledgement';

    public const GROUPS = [self::GROUP_EQUIPMENT, self::GROUP_ACTIONS, self::GROUP_INCIDENTS, self::GROUP_ACKNOWLEDGEMENT];

    /** Threshold of a digest at or below which it is also emailed. */
    public const MAIL_AT_DAYS = 7;

    /** @var array<string, Collection<int, User>> */
    protected array $roleCache = [];

    /** @var array<int, User|null> */
    protected array $employeeUsers = [];

    public function __construct(
        protected ExpiryRegisterService $register,
        protected IncidentNotificationService $people,
        protected HealthSafetySettings $settings,
    ) {}

    /**
     * @param  list<string>  $groups  any of GROUPS; empty means every group except the acknowledgement-only one
     * @return array{dry_run: bool, items: int, silent: int, recipients: int, sent: int, failed: int, lines: list<string>}
     */
    public function run(array $groups = [], bool $dryRun = false): array
    {
        $groups = $groups === [] ? [self::GROUP_EQUIPMENT, self::GROUP_ACTIONS, self::GROUP_INCIDENTS] : $groups;
        $this->roleCache = [];
        $this->employeeUsers = [];

        if (! Schema::hasTable('hs_alert_log')) {
            return ['dry_run' => $dryRun, 'items' => 0, 'silent' => 0, 'recipients' => 0, 'sent' => 0, 'failed' => 0, 'lines' => ['The alert log table does not exist: run the migrations.']];
        }

        $entries = collect();

        if (in_array(self::GROUP_EQUIPMENT, $groups, true)) {
            $entries = $entries->concat($this->equipmentEntries());
        }

        if (in_array(self::GROUP_ACTIONS, $groups, true)) {
            $entries = $entries->concat($this->actionEntries());
        }

        if (in_array(self::GROUP_INCIDENTS, $groups, true) || in_array(self::GROUP_ACKNOWLEDGEMENT, $groups, true)) {
            $entries = $entries->concat($this->acknowledgementEntries());
        }

        if (in_array(self::GROUP_INCIDENTS, $groups, true)) {
            $entries = $entries->concat($this->investigationEntries());
        }

        $silent = 0;
        $digests = [];
        $claimed = [];

        foreach ($entries as $index => $entry) {
            if ($entry['unsent'] === []) {
                continue;
            }

            if (! $entry['notify']) {
                // Crossed higher thresholds only: remembered, never announced.
                $silent++;

                if (! $dryRun) {
                    $this->claim($entry, $entry['unsent']);
                }

                continue;
            }

            if (! $dryRun && ! $this->claim($entry, $entry['unsent'])) {
                continue;   // another run announced it a moment ago
            }

            $recipients = $this->recipients($entry);

            if ($recipients->isEmpty()) {
                // Nobody to tell yet (no officer for the region): leave it unannounced so a later run can.
                if (! $dryRun) {
                    $this->release($entry);
                }

                continue;
            }

            $claimed[$index] = ['entry' => $entry, 'delivered' => 0, 'failed' => 0];

            foreach ($recipients as $user) {
                $digests[$user->id]['user'] = $user;
                $digests[$user->id]['entries'][] = $index;
            }
        }

        $sent = 0;
        $failed = 0;
        $lines = [];

        foreach ($digests as $digest) {
            /** @var User $user */
            $user = $digest['user'];
            $items = collect($digest['entries'])->map(fn (int $index) => $claimed[$index]['entry']);
            $text = $this->digestText($items);
            $lines[] = sprintf('%s (%s): %s%s', $user->full_name ?? $user->staff_id, $user->staff_id, $text['message'], $text['mail'] ? ' [email too]' : '');

            if ($dryRun) {
                continue;
            }

            try {
                $user->notify(new GeneralDatabaseNotification(
                    $text['title'],
                    $text['message'],
                    $this->digestUrl($user, $items),
                    'health_safety',
                    ['type' => 'hs_alert_digest', 'items' => $items->count()],
                    $text['mail'],
                ));

                $sent++;

                foreach ($digest['entries'] as $index) {
                    $claimed[$index]['delivered']++;
                }
            } catch (\Throwable $exception) {
                $failed++;
                Log::error('Health & Safety alert digest failed for user '.$user->id.': '.$exception->getMessage());

                foreach ($digest['entries'] as $index) {
                    $claimed[$index]['failed']++;
                }
            }
        }

        if (! $dryRun) {
            // An item nobody could be told about is not remembered as announced, so the next run tries again.
            foreach ($claimed as $info) {
                if ($info['delivered'] === 0 && $info['failed'] > 0) {
                    $this->release($info['entry']);
                }
            }
        }

        return [
            'dry_run' => $dryRun,
            'items' => count($claimed),
            'silent' => $silent,
            'recipients' => count($digests),
            'sent' => $sent,
            'failed' => $failed,
            'lines' => $lines,
        ];
    }

    // ------------------------------------------------------------------ the three kinds of entry

    /** @return Collection<int, array<string, mixed>> */
    protected function equipmentEntries(): Collection
    {
        $thresholds = $this->settings->get('hs_alert_thresholds');

        if ($thresholds === []) {
            return collect();
        }

        $rows = $this->register->rows(null, ['horizon' => (string) max(1, max($thresholds))]);
        $logged = $this->logged($rows->groupBy('type')->map->pluck('item_id'));

        return $rows->map(function (array $row) use ($thresholds, $logged) {
            $dueKey = $row['due_on']->toDateString();
            $crossed = array_values(array_filter($thresholds, fn (int $threshold) => $row['days'] <= $threshold));

            return $this->entry(
                group: self::GROUP_EQUIPMENT,
                alertType: $row['type'],
                itemId: $row['item_id'],
                dueKey: $dueKey,
                crossed: $crossed,
                logged: $logged[$row['type'].'|'.$row['item_id'].'|'.$dueKey] ?? [],
                lowestIsMostUrgent: true,
                extra: ['row' => $row, 'days' => $row['days'], 'label' => $row['type_label'], 'example' => $row['what'].' (due '.$row['due_on']->format('d M').')'],
            );
        })->filter()->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    protected function actionEntries(): Collection
    {
        $thresholds = $this->settings->get('hs_action_alert_thresholds');

        if ($thresholds === []) {
            return collect();
        }

        $horizon = max($thresholds);
        $actions = HsIncidentAction::query()
            ->with(['incident:id,reference,region_id,status', 'assignee'])
            ->where('status', HsIncidentAction::STATUS_OPEN)
            ->whereDate('due_on', '<=', today()->addDays(max(0, $horizon))->toDateString())
            ->whereHas('incident', fn ($query) => $query->whereNotIn('status', [HsIncident::STATUS_CLOSED, HsIncident::STATUS_CANCELLED]))
            ->get();

        $logged = $this->logged(collect(['action_due' => $actions->pluck('id')]));

        return $actions->map(function (HsIncidentAction $action) use ($thresholds, $logged) {
            $days = (int) today()->diffInDays($action->due_on->copy()->startOfDay(), false);
            $dueKey = $action->due_on->toDateString();
            $crossed = array_values(array_filter($thresholds, fn (int $threshold) => $days <= $threshold));

            return $this->entry(
                group: self::GROUP_ACTIONS,
                alertType: 'action_due',
                itemId: $action->id,
                dueKey: $dueKey,
                crossed: $crossed,
                logged: $logged['action_due|'.$action->id.'|'.$dueKey] ?? [],
                lowestIsMostUrgent: true,
                extra: ['action' => $action, 'days' => $days, 'label' => 'Safety action', 'example' => $action->incident->reference.' action due '.$action->due_on->format('d M')],
            );
        })->filter()->values();
    }

    /** Reports not acknowledged within the window (tier 1) and twice it (tier 2). @return Collection<int, array<string, mixed>> */
    protected function acknowledgementEntries(): Collection
    {
        $hours = max(1, (int) $this->settings->get('hs_ack_hours'));
        $incidents = HsIncident::query()
            ->where('status', HsIncident::STATUS_REPORTED)
            ->where('created_at', '<=', now()->subHours($hours))
            ->get(['id', 'reference', 'region_id', 'created_at', 'status']);

        $logged = $this->logged(collect(['incident_ack' => $incidents->pluck('id')]));

        return $incidents->map(function (HsIncident $incident) use ($hours, $logged) {
            $crossed = [1];

            if ($incident->created_at->lte(now()->subHours(2 * $hours))) {
                $crossed[] = 2;
            }

            return $this->entry(
                group: self::GROUP_ACKNOWLEDGEMENT,
                alertType: 'incident_ack',
                itemId: $incident->id,
                dueKey: 'n/a',
                crossed: $crossed,
                logged: $logged['incident_ack|'.$incident->id.'|n/a'] ?? [],
                lowestIsMostUrgent: false,
                extra: ['incident' => $incident, 'label' => 'Report not acknowledged', 'example' => $incident->reference],
            );
        })->filter()->values();
    }

    /** Investigations past their due date (tier 1) and a week later (tier 2). @return Collection<int, array<string, mixed>> */
    protected function investigationEntries(): Collection
    {
        $days = max(1, (int) $this->settings->get('hs_investigation_due_days'));
        $incidents = HsIncident::query()
            ->whereIn('status', [HsIncident::STATUS_ACKNOWLEDGED, HsIncident::STATUS_INVESTIGATING])
            ->whereNotNull('acknowledged_at')
            ->where('acknowledged_at', '<=', now()->subDays($days))
            ->get(['id', 'reference', 'region_id', 'owner_user_id', 'acknowledged_at', 'status']);

        $logged = $this->logged(collect(['incident_investigation' => $incidents->pluck('id')]));

        return $incidents->map(function (HsIncident $incident) use ($days, $logged) {
            $due = $incident->acknowledged_at->copy()->addDays($days)->startOfDay();

            if (! $due->lt(today())) {
                return null;
            }

            $crossed = [1];

            if ($due->copy()->addDays(7)->lte(today())) {
                $crossed[] = 2;
            }

            $dueKey = $due->toDateString();

            return $this->entry(
                group: self::GROUP_INCIDENTS,
                alertType: 'incident_investigation',
                itemId: $incident->id,
                dueKey: $dueKey,
                crossed: $crossed,
                logged: $logged['incident_investigation|'.$incident->id.'|'.$dueKey] ?? [],
                lowestIsMostUrgent: false,
                extra: ['incident' => $incident, 'label' => 'Investigation overdue', 'example' => $incident->reference.' (due '.$due->format('d M').')'],
            );
        })->filter()->values();
    }

    // ------------------------------------------------------------------ the shared rule

    /**
     * One item's alert decision. For dates the most urgent crossed threshold is the LOWEST number; for tiers it is the
     * HIGHEST.
     *
     * @param  list<int>  $crossed
     * @param  list<int>  $logged
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>|null null when nothing is crossed
     */
    protected function entry(string $group, string $alertType, int $itemId, string $dueKey, array $crossed, array $logged, bool $lowestIsMostUrgent, array $extra): ?array
    {
        if ($crossed === []) {
            return null;
        }

        $mostUrgent = $lowestIsMostUrgent ? min($crossed) : max($crossed);
        $unsent = array_values(array_diff($crossed, $logged));

        return [
            'group' => $group,
            'alert_type' => $alertType,
            'item_id' => $itemId,
            'due_key' => $dueKey,
            'crossed' => $crossed,
            'unsent' => $unsent,
            'threshold' => $mostUrgent,
            'notify' => in_array($mostUrgent, $unsent, true),
            'dates' => $lowestIsMostUrgent,
        ] + $extra;
    }

    /**
     * What is already in the log for these items, as "type|item|due_key" => thresholds.
     *
     * @param  Collection<string, Collection<int, int>>  $idsByType
     * @return array<string, list<int>>
     */
    protected function logged(Collection $idsByType): array
    {
        $logged = [];

        foreach ($idsByType as $type => $ids) {
            foreach ($ids->unique()->chunk(500) as $chunk) {
                foreach (DB::table('hs_alert_log')->where('alert_type', $type)->whereIn('item_id', $chunk->all())->get(['item_id', 'due_key', 'threshold']) as $row) {
                    $logged[$type.'|'.$row->item_id.'|'.$row->due_key][] = (int) $row->threshold;
                }
            }
        }

        return $logged;
    }

    /**
     * Writes the log rows with insert-or-ignore (the unique index is the lock). True when THIS run wrote the row of the
     * threshold being announced, so only one of two overlapping runs goes on to notify.
     *
     * @param  array<string, mixed>  $entry
     * @param  list<int>  $thresholds
     */
    protected function claim(array $entry, array $thresholds): bool
    {
        $won = ! $entry['notify'];

        foreach ($thresholds as $threshold) {
            $inserted = DB::table('hs_alert_log')->insertOrIgnore([
                'alert_type' => $entry['alert_type'],
                'item_id' => $entry['item_id'],
                'due_key' => $entry['due_key'],
                'threshold' => $threshold,
                'sent_at' => now(),
            ]);

            if ($threshold === $entry['threshold'] && $inserted === 1) {
                $won = true;
            }
        }

        return $won;
    }

    /** @param  array<string, mixed>  $entry */
    protected function release(array $entry): void
    {
        DB::table('hs_alert_log')
            ->where('alert_type', $entry['alert_type'])
            ->where('item_id', $entry['item_id'])
            ->where('due_key', $entry['due_key'])
            ->where('threshold', $entry['threshold'])
            ->delete();
    }

    // ------------------------------------------------------------------ who is told

    /**
     * @param  array<string, mixed>  $entry
     * @return Collection<int, User>
     */
    protected function recipients(array $entry): Collection
    {
        $people = collect();

        switch ($entry['group']) {
            case self::GROUP_EQUIPMENT:
                $row = $entry['row'];
                $people = $people->merge($this->role('hs_officer', $row['region_id']));

                // The named responsible person hears about their OWN items only.
                if ($row['responsible_employee_id']) {
                    $people->push($this->userOfEmployeeId((int) $row['responsible_employee_id']));
                }

                // The Health & Safety Manager hears about what is already overdue (zero and below), in every region.
                if ($row['days'] <= 0) {
                    $people = $people->merge($this->role('hs_manager', null));
                }
                break;

            case self::GROUP_ACTIONS:
                $action = $entry['action'];
                $people->push($this->people->userOfEmployee($action->assignee));

                // At the overdue threshold the officer is told as well.
                if ($entry['threshold'] < 0) {
                    $people = $people->merge($this->role('hs_officer', $action->incident->region_id));
                }
                break;

            case self::GROUP_ACKNOWLEDGEMENT:
                $incident = $entry['incident'];
                $people = $entry['threshold'] >= 2
                    ? $this->role('hs_manager', null)->merge($this->role('regional_chief_manager', $incident->region_id))
                    : $this->role('hs_officer', $incident->region_id);
                break;

            case self::GROUP_INCIDENTS:
                $incident = $entry['incident'];
                $people = $entry['threshold'] >= 2
                    ? $this->role('hs_manager', null)
                    : $this->role('hs_officer', $incident->region_id);

                // A named owner is chased too at tier 1.
                if ($entry['threshold'] < 2 && $incident->owner_user_id) {
                    $people->push(User::query()->active()->find($incident->owner_user_id));
                }
                break;
        }

        return $people->filter()->unique('id')->values();
    }

    /** The login of an employee, looked up once per run (a few people look after many units). */
    protected function userOfEmployeeId(int $employeeId): ?User
    {
        if (! array_key_exists($employeeId, $this->employeeUsers)) {
            $this->employeeUsers[$employeeId] = $this->people->userOfEmployee(Employee::query()->find($employeeId));
        }

        return $this->employeeUsers[$employeeId];
    }

    /** @return Collection<int, User> */
    protected function role(string $role, ?int $regionId): Collection
    {
        return $this->roleCache[$role.'|'.($regionId ?? 'all')] ??= $this->people->usersWithRole([$role], $regionId);
    }

    // ------------------------------------------------------------------ the digest

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array{title: string, message: string, mail: bool}
     */
    protected function digestText(Collection $items): array
    {
        $byLabel = $items->groupBy('label')->map->count();
        $parts = $byLabel->map(fn (int $count, string $label) => $count.' '.strtolower($label).($count === 1 ? '' : 's'))->values()->all();
        $examples = $items->sortBy(fn (array $item) => $item['dates'] ? $item['days'] : -$item['threshold'])->take(3)->pluck('example')->all();

        $title = $items->count() === 1 ? 'Health & Safety: 1 item needs attention' : 'Health & Safety: '.$items->count().' items need attention';
        $message = implode(', ', $parts).'. e.g. '.implode('; ', $examples).($items->count() > count($examples) ? '; and '.($items->count() - count($examples)).' more.' : '.');

        // Email only when a date threshold of 7 days or less (including due today and overdue) is in the digest.
        $mail = $items->contains(fn (array $item) => $item['dates'] && $item['threshold'] <= self::MAIL_AT_DAYS);

        return ['title' => $title, 'message' => $message, 'mail' => $mail];
    }

    /** Where the digest's link goes: the page that lists what it is about, one the person is allowed to open. @param  Collection<int, array<string, mixed>>  $items */
    protected function digestUrl(User $user, Collection $items): string
    {
        $can = fn (string $permission) => $user->hasRoles('super_admin') || $user->hasPermission($permission);
        $groups = $items->pluck('group')->unique();

        if ($groups->contains(fn ($group) => in_array($group, [self::GROUP_ACKNOWLEDGEMENT, self::GROUP_INCIDENTS], true)) && $can('health_safety.view_incidents')) {
            return route('health_safety.incidents', ['status' => 'open']);
        }

        if ($groups->contains(self::GROUP_EQUIPMENT)) {
            return $can('health_safety.view_equipment')
                ? route('health_safety.expiry-register')
                : route('health_safety.my-equipment');
        }

        return route('health_safety.actions');
    }
}
