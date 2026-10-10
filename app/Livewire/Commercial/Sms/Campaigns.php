<?php

namespace App\Livewire\Commercial\Sms;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\CommercialSmsCampaign;
use App\Models\Permission;
use App\Services\Commercial\Sms\SmsAccess;
use App\Services\Commercial\Sms\SmsCampaignException;
use App\Services\Commercial\Sms\SmsCampaignService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

/** The campaigns of the person's region (SmsAccess scope), newest first; open ones can be cancelled by those who may send. */
class Campaigns extends Component
{
    use EnforcesModuleAccess;
    use WithPagination;

    public string $status = '';

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_COMMERCIAL);
        SmsAccess::authorize(Auth::user(), SmsAccess::SEND, SmsAccess::REPORTS);
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function cancel(int $id, SmsCampaignService $campaigns): void
    {
        SmsAccess::authorize(Auth::user(), SmsAccess::SEND);

        try {
            $campaigns->cancel(Auth::user(), $id);
            $this->dispatch('toast', type: 'success', message: 'The campaign is being cancelled.');
        } catch (SmsCampaignException $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());
        }
    }

    public function render()
    {
        SmsAccess::authorize(Auth::user(), SmsAccess::SEND, SmsAccess::REPORTS);

        $campaigns = SmsAccess::scopeCampaigns(CommercialSmsCampaign::query(), Auth::user())
            ->when($this->status !== '' && in_array($this->status, ['scheduled', 'queued', 'running', 'cancelling', 'completed', 'cancelled', 'failed', 'blocked'], true), fn ($q) => $q->where('status', $this->status))
            ->with('creator:id,full_name')->orderByDesc('id')->paginate(15);

        return view('livewire.commercial.sms.campaigns', [
            'campaigns' => $campaigns,
            'canSend' => SmsAccess::allows(Auth::user(), SmsAccess::SEND),
            'canSeeReports' => SmsAccess::allows(Auth::user(), SmsAccess::REPORTS),
        ]);
    }
}
