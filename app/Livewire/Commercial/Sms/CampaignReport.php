<?php

namespace App\Livewire\Commercial\Sms;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\Permission;
use App\Services\Commercial\Sms\SmsAccess;
use App\Services\Commercial\Sms\SmsCampaignException;
use App\Services\Commercial\Sms\SmsCampaignService;
use App\Services\Commercial\Sms\SmsReportService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The delivery report of one campaign. The component keeps only the campaign id and re-resolves it through SmsAccess::findCampaign on
 * every call, so a campaign of another region is a 404 and a person who loses the permission loses the page.
 */
class CampaignReport extends Component
{
    use EnforcesModuleAccess;

    #[Locked]
    public int $campaignId = 0;

    public string $status = '';

    public ?int $after = null;

    /** @var list<int|null> */
    public array $before = [];

    public function mount(int $campaign): void
    {
        $this->enforceLivewireModule(Permission::MODULE_COMMERCIAL);
        SmsAccess::authorize(Auth::user(), SmsAccess::REPORTS);
        SmsAccess::findCampaign($campaign, Auth::user());
        $this->campaignId = $campaign;
    }

    public function updatedStatus(): void
    {
        $this->after = null;
        $this->before = [];
    }

    public function nextPage(?int $cursor): void
    {
        $this->before[] = $this->after;
        $this->after = $cursor;
    }

    public function previousPage(): void
    {
        $this->after = $this->before ? array_pop($this->before) : null;
    }

    public function cancel(SmsCampaignService $campaigns): void
    {
        SmsAccess::authorize(Auth::user(), SmsAccess::SEND);

        try {
            $campaigns->cancel(Auth::user(), $this->campaignId);
            $this->dispatch('toast', type: 'success', message: 'The campaign is being cancelled.');
        } catch (SmsCampaignException $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());
        }
    }

    public function render(SmsReportService $reports)
    {
        SmsAccess::authorize(Auth::user(), SmsAccess::REPORTS);

        $campaign = SmsAccess::findCampaign($this->campaignId, Auth::user());
        $details = Auth::user()->hasRoles('super_admin') || Auth::user()->hasPermission('commercial.view_customer_details');
        $summary = $reports->summary($campaign);

        return view('livewire.commercial.sms.campaign-report', [
            'campaign' => $campaign->load('creator:id,full_name'),
            'summary' => $summary,
            'page' => $summary['purged'] ? null : $reports->recipients($campaign, $this->status ?: null, $this->after, $details),
            'statuses' => config('gwl.commercial_sms_delivery_reports') ? SmsReportService::STATUS_LABELS : array_diff_key(SmsReportService::STATUS_LABELS, ['delivered' => 1]),
            'details' => $details,
            'canSend' => SmsAccess::allows(Auth::user(), SmsAccess::SEND),
            'canExport' => Auth::user()->hasRoles('super_admin') || Auth::user()->hasPermission('commercial.export_reports'),
            'audienceText' => app(\App\Services\Commercial\Sms\SmsAudienceResolver::class)->describe(app(\App\Services\Commercial\Sms\SmsAudienceResolver::class)->specOf($campaign)),
        ]);
    }
}
