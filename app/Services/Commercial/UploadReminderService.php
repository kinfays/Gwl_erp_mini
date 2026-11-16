<?php

namespace App\Services\Commercial;

use App\Models\CommercialImportBatch;
use App\Models\CommercialReminderState;
use App\Models\District;
use App\Models\User;
use App\Notifications\GeneralDatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tells officers when an upload is overdue: no non-voided report of a type has arrived for a region within the
 * configured number of days (gwl.commercial_reminder_reading_days / _billing_days; placeholders until the real cadence
 * is confirmed, design 10 question 9).
 *
 * overdue() is pure and is also what the Summary page uses to show its badge, so the page and the notification agree.
 * remind() is what the scheduled command runs: in-app notifications to the officers who upload for that region, at most
 * once every commercial_reminder_repeat_days for the same overdue upload.
 *
 * Phase 8f (design 8.45) adds the two route-grain reports: reading by route and the Detailed Billing Report, which use the SAME two limits (the reading
 * limit for reading by route, the billing limit for detailed billing; no new setting). They count only LIVE uploads (a queued, blocked or failed one is
 * not an upload that arrived), and because one file can hold some districts and not others they are also judged PER DISTRICT: a district whose newest
 * upload that holds a row of it is older than the limit (or that has none while the region has uploaded) is overdue. A region-wide officer is told the
 * region; a district user (who holds commercial.upload_reports) is told only their own district. The wording holds a region or district name, a report
 * name and day counts: no customer, CCA or route.
 */
class UploadReminderService
{
    /** Report type => the config key holding its limit in days. */
    public const LIMITS = [
        CommercialImportBatch::TYPE_READING_SUMMARY => 'commercial_reminder_reading_days',
        CommercialImportBatch::TYPE_BILLING_SUMMARY => 'commercial_reminder_billing_days',
        CommercialImportBatch::TYPE_READING_ROUTE_SUMMARY => 'commercial_reminder_reading_days',
        CommercialImportBatch::TYPE_BILLING_DETAILS => 'commercial_reminder_billing_days',
    ];

    /** The route-grain report types (live uploads only, and also judged district by district) => the table whose rows say which districts an upload holds. */
    public const ROUTE_TYPES = [
        CommercialImportBatch::TYPE_READING_ROUTE_SUMMARY => 'commercial_reading_route_stats',
        CommercialImportBatch::TYPE_BILLING_DETAILS => 'commercial_billing_detail_rollups',
    ];

    public const LABELS = [
        CommercialImportBatch::TYPE_READING_SUMMARY => 'meter reading',
        CommercialImportBatch::TYPE_BILLING_SUMMARY => 'billing',
        CommercialImportBatch::TYPE_READING_ROUTE_SUMMARY => 'reading by route',
        CommercialImportBatch::TYPE_BILLING_DETAILS => 'detailed billing',
    ];

    /**
     * Region + report type pairs whose latest upload is older than the limit. A region that has never uploaded a type is
     * not listed: there is nothing to be late against.
     *
     * @param  Collection<int, CommercialImportBatch>  $batches  non-voided, with region
     * @return list<array<string, mixed>>
     */
    public function overdue(Collection $batches, Carbon $asOf): array
    {
        $overdue = [];

        foreach (self::LIMITS as $type => $configKey) {
            $limit = (int) config('gwl.'.$configKey);

            foreach ($this->ofType($batches, $type)->groupBy('region_id') as $regionId => $group) {
                $latest = $group->sortByDesc(fn (CommercialImportBatch $batch) => [$this->uploadedAt($batch)->timestamp, $batch->id])->first();
                $uploaded = $this->uploadedAt($latest);

                if ($limit > 0 && $uploaded->lt($asOf->copy()->subDays($limit))) {
                    $overdue[] = [
                        'region_id' => (int) $regionId,
                        'region' => $latest->region?->region_name ?? $latest->region_label_raw ?? 'Region '.$regionId,
                        'report_type' => $type,
                        'label' => self::LABELS[$type],
                        'last_batch_id' => $latest->id,
                        'last_upload' => $uploaded,
                        'days' => (int) $uploaded->diffInDays($asOf),
                        'limit' => $limit,
                    ];
                }
            }
        }

        usort($overdue, fn (array $a, array $b) => [$b['days'], $a['region']] <=> [$a['days'], $b['region']]);

        return $overdue;
    }

    /**
     * The uploads of one type that count: for the route-grain reports only the live ones (imported or superseded), for the older reports every non-voided one.
     *
     * @param  Collection<int, CommercialImportBatch>  $batches
     * @return Collection<int, CommercialImportBatch>
     */
    protected function ofType(Collection $batches, string $type): Collection
    {
        $group = $batches->where('report_type', $type)->whereNotNull('region_id');

        return isset(self::ROUTE_TYPES[$type])
            ? $group->filter(fn (CommercialImportBatch $b) => in_array($b->status, [CommercialImportBatch::STATUS_IMPORTED, CommercialImportBatch::STATUS_SUPERSEDED], true))
            : $group;
    }

    /**
     * The route-grain reports judged DISTRICT by district: a district whose newest live upload that holds a row of it is older than the limit, or that has
     * none at all while its region has uploaded the type, is overdue. $districtIds limits the answer (a district user's own districts; null = every district).
     *
     * @param  Collection<int, CommercialImportBatch>|null  $batches  live and not voided, with region; read when null
     * @param  list<int>|null  $districtIds
     * @return list<array<string, mixed>>
     */
    public function overdueDistricts(Carbon $asOf, ?array $districtIds = null, ?Collection $batches = null): array
    {
        $batches ??= CommercialImportBatch::query()->notVoided()->whereIn('report_type', array_keys(self::ROUTE_TYPES))->with('region')->get();
        $overdue = [];

        foreach (self::ROUTE_TYPES as $type => $table) {
            $limit = (int) config('gwl.'.self::LIMITS[$type]);
            $live = $this->ofType($batches, $type);

            if ($limit <= 0 || $live->isEmpty()) {
                continue;
            }

            $query = DB::table($table)->whereIn('batch_id', $live->pluck('id')->all())->whereNotNull('district_id');

            if ($districtIds !== null) {
                $query->whereIn('district_id', $districtIds);
            }

            // district id => the live batches that hold a row of it
            $present = $query->select('batch_id', 'district_id')->distinct()->get()->groupBy('district_id')->map(fn ($rows) => $rows->pluck('batch_id')->map(fn ($id) => (int) $id)->all());
            $byId = $live->keyBy('id');

            foreach ($live->pluck('region_id')->unique() as $regionId) {
                $districts = District::query()->where('region_id', $regionId)->when($districtIds !== null, fn ($q) => $q->whereIn('id', $districtIds))->orderBy('district_name')->get();

                foreach ($districts as $district) {
                    $mine = collect($present[$district->id] ?? [])->map(fn (int $id) => $byId[$id] ?? null)->filter(fn ($b) => $b !== null && (int) $b->region_id === (int) $regionId);
                    $latest = $mine->sortByDesc(fn (CommercialImportBatch $b) => [$this->uploadedAt($b)->timestamp, $b->id])->first();
                    $uploaded = $latest ? $this->uploadedAt($latest) : null;

                    if ($uploaded !== null && ! $uploaded->lt($asOf->copy()->subDays($limit))) {
                        continue;
                    }

                    $overdue[] = [
                        'region_id' => (int) $regionId,
                        'region' => $district->district_name,
                        'district_id' => (int) $district->id,
                        'district' => $district->district_name,
                        'report_type' => $type,
                        'label' => self::LABELS[$type],
                        'last_batch_id' => $latest?->id,
                        'last_upload' => $uploaded,
                        'days' => $uploaded === null ? null : (int) $uploaded->diffInDays($asOf),
                        'limit' => $limit,
                    ];
                }
            }
        }

        usort($overdue, fn (array $a, array $b) => [$b['days'] ?? PHP_INT_MAX, $a['district']] <=> [$a['days'] ?? PHP_INT_MAX, $b['district']]);

        return $overdue;
    }

    /**
     * The overdue list of a screen: for a region-wide viewer the region items (the four report types), for a district user only the route-grain reports of
     * THEIR district(s) (the regional status is not theirs).
     *
     * @param  Collection<int, CommercialImportBatch>  $batches  the viewer's own, non-voided, with region
     * @param  list<int>|null  $districtIds  null = no district limit
     * @return list<array<string, mixed>>
     */
    public function overdueFor(Collection $batches, Carbon $asOf, ?array $districtIds): array
    {
        return $districtIds === null ? $this->overdue($batches, $asOf) : $this->overdueDistricts($asOf, $districtIds, $batches);
    }

    /**
     * Sends the reminders that are due. Does nothing (and says so) while reminders are switched off in the settings.
     *
     * @return array{enabled: bool, overdue: int, reminded: int, notifications: int, skipped: int}
     */
    public function remind(?Carbon $asOf = null, bool $dryRun = false): array
    {
        $asOf ??= now();
        $summary = ['enabled' => (bool) config('gwl.commercial_reminders_enabled'), 'overdue' => 0, 'reminded' => 0, 'notifications' => 0, 'skipped' => 0];

        if (! $summary['enabled']) {
            return $summary;
        }

        $batches = CommercialImportBatch::query()->notVoided()->with('region')->get();
        $overdue = $this->overdue($batches, $asOf);
        $summary['overdue'] = count($overdue);
        $repeat = max(1, (int) config('gwl.commercial_reminder_repeat_days', 7));

        foreach ($overdue as $item) {
            $this->send($summary, $item, $item['report_type'], $this->recipients($item['region_id']), $asOf, $repeat, $dryRun);
        }

        // the route-grain reports, district by district: told only to the district users of that district
        $districts = $this->overdueDistricts($asOf, null, $batches);
        $summary['overdue'] += count($districts);

        if ($districts !== []) {
            $users = $this->districtUsers();

            foreach ($districts as $item) {
                $told = $users->filter(fn (array $u) => in_array($item['district_id'], $u['districts'], true))->pluck('user');

                // a district nobody uploads for has nobody to tell: it stays on the district user's own list but sends nothing
                if ($told->isNotEmpty()) {
                    $this->send($summary, $item, $item['report_type'].'@d'.$item['district_id'], $told, $asOf, $repeat, $dryRun);
                }
            }
        }

        return $summary;
    }

    /**
     * One reminder: due when never sent, when a newer upload has come and gone late since, or when the last one is older than the repeat interval.
     *
     * @param  array<string, int>  $summary
     * @param  array<string, mixed>  $item
     * @param  Collection<int, User>  $recipients
     */
    protected function send(array &$summary, array $item, string $stateKey, Collection $recipients, Carbon $asOf, int $repeat, bool $dryRun): void
    {
        $state = CommercialReminderState::query()->where(['region_id' => $item['region_id'], 'report_type' => $stateKey])->first();
        $due = ! $state?->last_reminded_at
            || ($item['last_upload'] !== null && $state->last_reminded_at->lt($item['last_upload']))
            || $state->last_reminded_at->lte($asOf->copy()->subDays($repeat));

        if (! $due) {
            $summary['skipped']++;

            return;
        }

        if ($dryRun) {
            $summary['reminded']++;
            $summary['notifications'] += $recipients->count();

            return;
        }

        $when = $item['last_upload'] === null
            ? 'No '.$item['label'].' report has been uploaded for '.$item['region'].' within the reminder limit of '.$item['limit'].' days.'
            : 'No '.$item['label'].' report has been uploaded for '.$item['region'].' for '.$item['days'].' days (the reminder limit is '.$item['limit'].'). The last one arrived on '.$item['last_upload']->format('d M Y').'.';

        foreach ($recipients as $user) {
            $user->notify(new GeneralDatabaseNotification(
                ucfirst($item['label']).' upload overdue: '.$item['region'],
                $when,
                route('commercial.batches'),
                'commercial',
                ['kind' => 'commercial_upload_overdue', 'region_id' => $item['region_id'], 'report_type' => $item['report_type']] + (isset($item['district_id']) ? ['district_id' => $item['district_id']] : []),
            ));
        }

        CommercialReminderState::query()->updateOrCreate(
            ['region_id' => $item['region_id'], 'report_type' => $stateKey],
            ['last_reminded_at' => $asOf, 'last_batch_id' => $item['last_batch_id']]
        );

        $summary['reminded']++;
        $summary['notifications'] += $recipients->count();
    }

    /**
     * The officers who upload for a region: active users holding commercial.upload_reports whose own region it is, plus
     * those who see every region (Head Office staff, Global Admin). The developer super_admin account is never told.
     *
     * @return Collection<int, User>
     */
    public function recipients(int $regionId): Collection
    {
        return User::query()
            ->active()
            ->visibleInErp()
            ->with(['roles.permissions', 'employee', 'employeeByStaffId'])
            ->get()
            ->filter(function (User $user) use ($regionId): bool {
                if (! $user->hasPermission('commercial.upload_reports')) {
                    return false;
                }

                // Phase 8a: an overdue upload is a region-wide status; a district user (who sees only their own district) is not told it
                if (CommercialScope::for($user)->isDistrictLimited()) {
                    return false;
                }

                $employee = $user->employee ?? $user->employeeByStaffId;

                return $user->hasRoles('admin')
                    || $employee?->location_type === 'HeadOffice'
                    || ($employee?->region_id !== null && (int) $employee->region_id === $regionId);
            })
            ->values();
    }

    /**
     * The active district users who upload (commercial.upload_reports) with the district(s) they see: told only about those (Phase 8f).
     *
     * @return Collection<int, array{user: User, districts: list<int>}>
     */
    protected function districtUsers(): Collection
    {
        return User::query()
            ->active()
            ->visibleInErp()
            ->with(['roles.permissions', 'employee', 'employeeByStaffId'])
            ->get()
            ->filter(fn (User $user) => $user->hasPermission('commercial.upload_reports') && CommercialScope::for($user)->isDistrictLimited())
            ->map(fn (User $user) => ['user' => $user, 'districts' => array_map('intval', CommercialScope::for($user)->districtIds() ?? [])])
            ->values();
    }

    protected function uploadedAt(CommercialImportBatch $batch): Carbon
    {
        return Carbon::parse($batch->imported_at ?? $batch->created_at);
    }
}
