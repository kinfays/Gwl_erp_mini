<?php

namespace App\Services\Commercial;

use App\Models\CommercialImportBatch;
use App\Models\CommercialReminderState;
use App\Models\User;
use App\Notifications\GeneralDatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Tells officers when an upload is overdue: no non-voided report of a type has arrived for a region within the
 * configured number of days (gwl.commercial_reminder_reading_days / _billing_days; placeholders until the real cadence
 * is confirmed, design 10 question 9).
 *
 * overdue() is pure and is also what the Summary page uses to show its badge, so the page and the notification agree.
 * remind() is what the scheduled command runs: in-app notifications to the officers who upload for that region, at most
 * once every commercial_reminder_repeat_days for the same overdue upload.
 */
class UploadReminderService
{
    /** Report type => the config key holding its limit in days. */
    public const LIMITS = [
        CommercialImportBatch::TYPE_READING_SUMMARY => 'commercial_reminder_reading_days',
        CommercialImportBatch::TYPE_BILLING_SUMMARY => 'commercial_reminder_billing_days',
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

            foreach ($batches->where('report_type', $type)->whereNotNull('region_id')->groupBy('region_id') as $regionId => $group) {
                $latest = $group->sortByDesc(fn (CommercialImportBatch $batch) => [$this->uploadedAt($batch)->timestamp, $batch->id])->first();
                $uploaded = $this->uploadedAt($latest);

                if ($limit > 0 && $uploaded->lt($asOf->copy()->subDays($limit))) {
                    $overdue[] = [
                        'region_id' => (int) $regionId,
                        'region' => $latest->region?->region_name ?? $latest->region_label_raw ?? 'Region '.$regionId,
                        'report_type' => $type,
                        'label' => $type === CommercialImportBatch::TYPE_READING_SUMMARY ? 'meter reading' : 'billing',
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

        $overdue = $this->overdue(CommercialImportBatch::query()->notVoided()->with('region')->get(), $asOf);
        $summary['overdue'] = count($overdue);
        $repeat = max(1, (int) config('gwl.commercial_reminder_repeat_days', 7));

        foreach ($overdue as $item) {
            $state = CommercialReminderState::query()->where(['region_id' => $item['region_id'], 'report_type' => $item['report_type']])->first();

            // Remind when never reminded, when a newer upload has come and gone late again since the last reminder, or
            // when the last reminder is older than the repeat interval.
            $due = ! $state?->last_reminded_at
                || $state->last_reminded_at->lt($item['last_upload'])
                || $state->last_reminded_at->lte($asOf->copy()->subDays($repeat));

            if (! $due) {
                $summary['skipped']++;

                continue;
            }

            $recipients = $this->recipients($item['region_id']);

            if ($dryRun) {
                $summary['reminded']++;
                $summary['notifications'] += $recipients->count();

                continue;
            }

            foreach ($recipients as $user) {
                $user->notify(new GeneralDatabaseNotification(
                    ucfirst($item['label']).' upload overdue: '.$item['region'],
                    'No '.$item['label'].' report has been uploaded for '.$item['region'].' for '.$item['days'].' days (the reminder limit is '.$item['limit'].'). The last one arrived on '.$item['last_upload']->format('d M Y').'.',
                    route('commercial.batches'),
                    'commercial',
                    ['kind' => 'commercial_upload_overdue', 'region_id' => $item['region_id'], 'report_type' => $item['report_type']],
                ));
            }

            CommercialReminderState::query()->updateOrCreate(
                ['region_id' => $item['region_id'], 'report_type' => $item['report_type']],
                ['last_reminded_at' => $asOf, 'last_batch_id' => $item['last_batch_id']]
            );

            $summary['reminded']++;
            $summary['notifications'] += $recipients->count();
        }

        return $summary;
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

                $employee = $user->employee ?? $user->employeeByStaffId;

                return $user->hasRoles('admin')
                    || $employee?->location_type === 'HeadOffice'
                    || ($employee?->region_id !== null && (int) $employee->region_id === $regionId);
            })
            ->values();
    }

    protected function uploadedAt(CommercialImportBatch $batch): Carbon
    {
        return Carbon::parse($batch->imported_at ?? $batch->created_at);
    }
}
