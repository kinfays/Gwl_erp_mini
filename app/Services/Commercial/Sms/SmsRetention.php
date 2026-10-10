<?php

namespace App\Services\Commercial\Sms;

use App\Models\CommercialSmsCampaign;
use App\Models\Permission;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Retention of "who got what": with commercial_sms_recipient_retention_days > 0 the recipient rows (customer id + status) of
 * campaigns that finished that long ago are deleted. The campaign and its totals stay, so the report still shows the counts.
 * 0 (the default) keeps everything.
 */
class SmsRetention
{
    public function __construct(protected SmsCampaignService $campaigns)
    {
    }

    /** @return array{campaigns: int, rows: int} */
    public function purge(bool $dryRun = false): array
    {
        $days = (int) config('gwl.commercial_sms_recipient_retention_days', 0);
        $result = ['campaigns' => 0, 'rows' => 0];

        if ($days <= 0) {
            return $result;
        }

        $old = CommercialSmsCampaign::query()->whereNotNull('finished_at')->where('finished_at', '<', now()->subDays($days))->whereNull('details_purged_at')->get();

        foreach ($old as $campaign) {
            $rows = (int) DB::table('commercial_sms_recipients')->where('campaign_id', $campaign->id)->count();
            $result['campaigns']++;
            $result['rows'] += $rows;

            if ($dryRun) {
                continue;
            }

            // Late delivery reports may have landed after the campaign finished: keep the final counts.
            $campaign->forceFill(['totals' => $this->campaigns->totals($campaign->id), 'details_purged_at' => now()])->save();
            DB::table('commercial_sms_recipients')->where('campaign_id', $campaign->id)->delete();

            Audit::log('commercial.sms_recipients_purged', Permission::MODULE_COMMERCIAL, CommercialSmsCampaign::class, $campaign->id, ['rows' => $rows, 'retention_days' => $days]);
        }

        return $result;
    }
}
