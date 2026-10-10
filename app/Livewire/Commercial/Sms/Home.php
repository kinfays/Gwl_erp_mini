<?php

namespace App\Livewire\Commercial\Sms;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\Permission;
use App\Services\Commercial\Sms\SmsAccess;
use App\Services\Commercial\Sms\SmsBalanceService;
use App\Services\Commercial\Sms\SmsGateway;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * SMS landing page: the balance (cached, with a throttled Refresh), a low-balance banner, and where to go next. Who may be here is
 * SmsAccess's decision, asked again on every action.
 */
class Home extends Component
{
    use EnforcesModuleAccess;

    public bool $refreshed = false;

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_COMMERCIAL);
        SmsAccess::authorize(Auth::user(), SmsAccess::SEND, SmsAccess::BALANCE, SmsAccess::REPORTS, SmsAccess::SETTINGS);
    }

    public function refreshBalance(SmsBalanceService $balance): void
    {
        SmsAccess::authorize(Auth::user(), SmsAccess::BALANCE);
        $balance->current(true);
        $this->refreshed = true;
    }

    public function render(SmsBalanceService $balance, SmsGateway $gateway)
    {
        SmsAccess::authorize(Auth::user(), SmsAccess::SEND, SmsAccess::BALANCE, SmsAccess::REPORTS, SmsAccess::SETTINGS);

        $user = Auth::user();
        $canSeeBalance = SmsAccess::allows($user, SmsAccess::BALANCE);

        return view('livewire.commercial.sms.home', [
            'balance' => $canSeeBalance ? $balance->current() : null,
            'configured' => $gateway->configured(),
            'canSend' => SmsAccess::allows($user, SmsAccess::SEND),
            'canSeeReports' => SmsAccess::allows($user, SmsAccess::REPORTS),
            'canManage' => SmsAccess::allows($user, SmsAccess::SETTINGS),
            'canSeeBalance' => $canSeeBalance,
            'regionNames' => array_map('mb_strtoupper', (array) config('gwl.commercial_sms_region_names', [])),
        ]);
    }
}
