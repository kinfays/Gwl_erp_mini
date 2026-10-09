<?php

namespace App\Services\Commercial\Customers;

use App\Models\CommercialCustomerBatch;
use App\Models\CommercialReminderState;
use App\Notifications\GeneralDatabaseNotification;
use App\Services\Commercial\UploadReminderService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "The customer list for <district> is overdue", per district, by the cadence set for it (weekly / monthly / off). A district
 * that has never uploaded is not listed (there is nothing to be late against). Month-end comparisons use the last batch of the
 * month, so this only decides WHEN to nag. Reminders go in-app to the officers of the district's region, at most once per
 * commercial_reminder_repeat_days for the same overdue upload; the state shares commercial_reminder_states.
 */
class CustomerUploadReminders
{
    public function __construct(protected CustomerCadence $cadence, protected UploadReminderService $recipients)
    {
    }

    /** @return list<array<string, mixed>> */
    public function overdue(?Carbon $asOf = null, ?int $regionRestriction = null): array
    {
        $asOf ??= now();
        $latest = CommercialCustomerBatch::query()->live()->whereNotNull('district_id')
            ->when($regionRestriction !== null, fn ($q) => $regionRestriction === 0 ? $q->whereRaw('1 = 0') : $q->where('region_id', $regionRestriction))
            ->orderByDesc('as_of_date')->orderByDesc('id')->get()->unique('district_id');
        $names = DB::table('districts')->whereIn('id', $latest->pluck('district_id')->all())->pluck('district_name', 'id');
        $overdue = [];

        foreach ($latest as $batch) {
            $cadence = $this->cadence->forDistrict((int) $batch->district_id);
            $limit = $this->cadence->limitDays($cadence);
            $uploaded = Carbon::parse($batch->imported_at ?? $batch->created_at);

            if ($limit !== null && $uploaded->lt($asOf->copy()->subDays($limit))) {
                $overdue[] = [
                    'district_id' => (int) $batch->district_id, 'district' => $names[$batch->district_id] ?? 'District '.$batch->district_id, 'region_id' => (int) $batch->region_id,
                    'cadence' => $cadence, 'limit' => $limit, 'last_upload' => $uploaded, 'days' => (int) $uploaded->diffInDays($asOf), 'last_batch_id' => (int) $batch->id,
                ];
            }
        }

        usort($overdue, fn (array $a, array $b) => [$b['days'], $a['district']] <=> [$a['days'], $b['district']]);

        return $overdue;
    }

    /** @return array{overdue: int, reminded: int, notifications: int, skipped: int} */
    public function remind(?Carbon $asOf = null, bool $dryRun = false): array
    {
        $asOf ??= now();
        $summary = ['overdue' => 0, 'reminded' => 0, 'notifications' => 0, 'skipped' => 0];

        if (! config('gwl.commercial_reminders_enabled') || ! config('gwl.commercial_customer_list_enabled')) {
            return $summary;
        }

        $overdue = $this->overdue($asOf);
        $summary['overdue'] = count($overdue);
        $repeat = max(1, (int) config('gwl.commercial_reminder_repeat_days', 7));

        foreach ($overdue as $item) {
            $key = 'customer_list:'.$item['district_id'];
            $state = CommercialReminderState::query()->where(['region_id' => $item['region_id'], 'report_type' => $key])->first();
            $due = ! $state?->last_reminded_at || $state->last_reminded_at->lt($item['last_upload']) || $state->last_reminded_at->lte($asOf->copy()->subDays($repeat));

            if (! $due) {
                $summary['skipped']++;

                continue;
            }

            $users = $this->recipients->recipients($item['region_id']);

            if (! $dryRun) {
                foreach ($users as $user) {
                    $user->notify(new GeneralDatabaseNotification(
                        'Customer list upload overdue: '.$item['district'],
                        'No customer list has been uploaded for '.$item['district'].' for '.$item['days'].' days (expected '.$item['cadence'].', reminder after '.$item['limit'].'). The last one arrived on '.$item['last_upload']->format('d M Y').'.',
                        route('commercial.customers.uploads'),
                        'commercial',
                        ['kind' => 'commercial_customer_upload_overdue', 'district_id' => $item['district_id']],
                    ));
                }

                CommercialReminderState::query()->updateOrCreate(['region_id' => $item['region_id'], 'report_type' => $key], ['last_reminded_at' => $asOf, 'last_batch_id' => null]);
            }

            $summary['reminded']++;
            $summary['notifications'] += $users->count();
        }

        return $summary;
    }
}
