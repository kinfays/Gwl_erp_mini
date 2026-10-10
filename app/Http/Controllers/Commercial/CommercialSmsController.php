<?php

namespace App\Http\Controllers\Commercial;

use App\Http\Controllers\Concerns\EnforcesModuleAccess;
use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\CommercialSmsCampaign;
use App\Services\Commercial\Sms\SmsAccess;
use App\Services\Commercial\Sms\SmsReportService;
use App\Support\Audit;
use Illuminate\Http\Request;

/**
 * Thin: the SMS page wrappers. Who may use the feature is SmsAccess's decision (flag, region lock, permission), asked here and
 * again inside every Livewire component and queued job.
 */
class CommercialSmsController extends Controller
{
    use EnforcesModuleAccess;

    protected function page(Request $request, string $view, array $permissions, array $data = [])
    {
        $this->enforceModule($request, Permission::MODULE_COMMERCIAL);
        SmsAccess::authorize($request->user(), ...$permissions);

        return view($view, $data);
    }

    public function home(Request $request)
    {
        return $this->page($request, 'commercial.sms.home', [SmsAccess::SEND, SmsAccess::BALANCE, SmsAccess::REPORTS, SmsAccess::SETTINGS]);
    }

    public function send(Request $request)
    {
        return $this->page($request, 'commercial.sms.send', [SmsAccess::SEND]);
    }

    public function campaigns(Request $request)
    {
        return $this->page($request, 'commercial.sms.campaigns', [SmsAccess::SEND, SmsAccess::REPORTS]);
    }

    public function campaign(Request $request, int $campaign)
    {
        SmsAccess::authorize($request->user(), SmsAccess::REPORTS);
        SmsAccess::findCampaign($campaign, $request->user());   // another region's campaign: 404

        return $this->page($request, 'commercial.sms.campaign', [SmsAccess::REPORTS], ['campaignId' => $campaign]);
    }

    public function templates(Request $request)
    {
        return $this->page($request, 'commercial.sms.templates', [SmsAccess::SEND]);
    }

    public function settings(Request $request)
    {
        return $this->page($request, 'commercial.sms.settings', [SmsAccess::SETTINGS]);
    }

    /** The recipient list of one campaign as an Excel file: masked numbers, string cells, a row cap, audited. */
    public function export(Request $request, int $campaign, SmsReportService $reports)
    {
        $this->enforceModule($request, Permission::MODULE_COMMERCIAL);

        $user = $request->user();
        SmsAccess::authorize($user, SmsAccess::REPORTS);
        abort_unless($user->hasRoles('super_admin') || $user->hasPermission('commercial.export_reports'), 403);

        $model = SmsAccess::findCampaign($campaign, $user);
        abort_if($model->details_purged_at !== null, 404);

        $details = $user->hasRoles('super_admin') || $user->hasPermission('commercial.view_customer_details');
        $path = tempnam(sys_get_temp_dir(), 'sms').'.xlsx';
        $result = $reports->write($path, $model, $details, (int) config('gwl.commercial_customer_export_max_rows', 100000));

        Audit::log('commercial.sms_report_exported', Permission::MODULE_COMMERCIAL, CommercialSmsCampaign::class, $model->id, ['rows' => $result['rows'], 'truncated' => $result['truncated'], 'names' => $details]);

        return response()->download($path, 'sms_campaign_'.$model->id.'_'.now()->format('Ymd').'.xlsx')->deleteFileAfterSend(true);
    }
}
