<?php

namespace App\Livewire\Commercial\Sms;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\CommercialCustomerCategory;
use App\Models\CommercialSmsTemplate;
use App\Models\Permission;
use App\Services\Commercial\Sms\SmsAccess;
use App\Services\Commercial\Sms\SmsAudienceException;
use App\Services\Commercial\Sms\SmsAudienceResolver;
use App\Services\Commercial\Sms\SmsBalanceService;
use App\Services\Commercial\Sms\SmsCampaignException;
use App\Services\Commercial\Sms\SmsCampaignService;
use App\Services\Commercial\Sms\SmsMessageBuilder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Send SMS, in four steps: 1 who (audience + filters, with an exact live count), 2 what (sender ID, template, message, parts and
 * cost), 3 check (three masked sample recipients, the count, the cost against the balance), 4 confirm (type the recipient count)
 * and send now or later. Everything the screen shows is asked of SmsCampaignService / SmsAudienceResolver; nothing here decides who
 * may be messaged. The component holds counts and rules, never a phone number: the samples are masked and fetched when drawn.
 */
class Send extends Component
{
    use EnforcesModuleAccess;

    public int $step = 1;

    // ---- step 1: who
    public string $type = 'all';

    public string $group = 'actb';

    /** @var list<int|string> */
    public array $districtIds = [];

    public string $routeId = '';

    public string $categoryGroup = '';

    public string $statusId = '';

    public bool $includeMissing = false;

    public string $minDebt = '';

    public string $bucket = '';

    public string $days = '60';

    public string $topMode = 'n';

    public string $topN = '';

    public string $topPct = '';

    public string $pasted = '';

    /** @var list<int> customers ticked on the lists, or found from a pasted list: set by the server only */
    #[Locked]
    public array $selectedIds = [];

    /** @var array<string, int>|null counts of the audience as it stands; null = the filters changed since */
    public ?array $counts = null;

    /** How many of the customers ticked on the lists were outside the region lock (counted, never named). */
    public int $tickedOutside = 0;

    /** @var array<string, int>|null what a pasted list came to */
    public ?array $pasteReport = null;

    // ---- step 2: what
    public string $senderId = '';

    public string $templateId = '';

    public string $body = '';

    public string $name = '';

    // ---- step 4: confirm
    public string $confirmCount = '';

    public bool $confirmChecked = false;

    public string $scheduleAt = '';

    #[Locked]
    public string $token = '';

    public function mount(SmsCampaignService $campaigns): void
    {
        $this->enforceLivewireModule(Permission::MODULE_COMMERCIAL);
        SmsAccess::authorize(Auth::user(), SmsAccess::SEND);

        $this->token = Str::random(32);
        $this->senderId = $campaigns->senderIds()[0] ?? '';
        $this->minDebt = (string) config('gwl.commercial_sms_debtors_min', 100);
        $this->days = (string) config('gwl.commercial_sms_no_payment_default_days', 60);
        $this->topN = (string) config('gwl.commercial_sms_most_valuable_top_n', 500);
        $this->topPct = (string) config('gwl.commercial_sms_most_valuable_top_pct', 5);

        // Customers ticked on the Find customers list (their ids; the region lock is applied again when the audience is built).
        $ticked = (array) session()->pull('sms.selected', []);

        if ($ticked !== []) {
            $this->selectedIds = array_values(array_unique(array_map('intval', $ticked)));
            $this->type = 'selected';
            $this->tickedOutside = app(SmsAudienceResolver::class)->checkSelected($this->selectedIds, SmsAccess::allowedRegionIds(Auth::user()))['outside'];
        }
    }

    // ---------------------------------------------------------------- state changes

    /** Any change to who is messaged makes the last count stale. */
    public function updated(string $property): void
    {
        if (in_array($property, ['type', 'group', 'districtIds', 'routeId', 'categoryGroup', 'statusId', 'includeMissing', 'minDebt', 'bucket', 'days', 'topMode', 'topN', 'topPct'], true)) {
            $this->counts = null;
        }

        if ($property === 'districtIds') {
            $this->routeId = '';
        }

        if ($property === 'templateId') {
            $this->useTemplate();
        }
    }

    protected function useTemplate(): void
    {
        $template = $this->templateId === '' ? null : SmsAccess::scopeRegion(CommercialSmsTemplate::query(), Auth::user())->find((int) $this->templateId);

        if ($template) {
            $this->body = (string) $template->body;
        }
    }

    /** @return array<string, mixed> */
    protected function audience(): array
    {
        $common = [
            'type' => $this->type,
            'include_missing' => $this->includeMissing,
        ];

        if ($this->type === 'selected') {
            return $common + ['customer_ids' => $this->selectedIds];
        }

        return $common + [
            'group' => $this->group,
            'district_ids' => array_map('intval', $this->districtIds),
            'route_id' => $this->routeId !== '' ? (int) $this->routeId : null,
            'category_group' => $this->categoryGroup !== '' ? $this->categoryGroup : null,
            'status_id' => $this->statusId !== '' ? (int) $this->statusId : null,
            'min_debt' => (float) $this->minDebt,
            'bucket' => $this->bucket !== '' ? (int) $this->bucket : null,
            'days' => (int) $this->days,
            'top_n' => $this->topMode === 'n' ? (int) $this->topN : null,
            'top_pct' => $this->topMode === 'pct' ? (float) $this->topPct : null,
        ];
    }

    /** @return array<string, mixed> */
    protected function input(): array
    {
        return [
            'audience' => $this->audience(),
            'sender_id' => $this->senderId,
            'body' => $this->body,
            'template_id' => $this->templateId !== '' ? (int) $this->templateId : null,
            'name' => $this->name,
            'scheduled_at' => $this->scheduleAt !== '' ? $this->scheduleAt : null,
            'confirm_count' => $this->confirmCount !== '' ? (int) preg_replace('/\D/', '', $this->confirmCount) : null,
            'confirm_checked' => $this->confirmChecked,
            'submission_token' => $this->token,
        ];
    }

    // ---------------------------------------------------------------- actions

    public function countAudience(SmsCampaignService $campaigns): void
    {
        SmsAccess::authorize(Auth::user(), SmsAccess::SEND);
        $this->resetErrorBag();

        try {
            $this->counts = $campaigns->preview(Auth::user(), $this->input())['counts'];
        } catch (SmsAudienceException $e) {
            $this->counts = null;
            $this->addError('audience', $e->getMessage());
        }
    }

    /** A pasted list of account numbers becomes the selected customers (the rest is only counted). */
    public function usePasted(SmsAudienceResolver $audiences): void
    {
        SmsAccess::authorize(Auth::user(), SmsAccess::SEND);
        $this->resetErrorBag();

        try {
            $result = $audiences->parseAccountList($this->pasted, SmsAccess::allowedRegionIds(Auth::user()));
        } catch (SmsAudienceException $e) {
            $this->addError('pasted', $e->getMessage());

            return;
        }

        $this->selectedIds = array_values(array_unique([...$this->selectedIds, ...$result['ids']]));
        $this->pasteReport = ['added' => count($result['ids']), 'invalid' => $result['invalid'], 'not_found' => $result['not_found'], 'outside' => $result['outside'], 'duplicates' => $result['duplicates']];
        $this->type = 'selected';
        $this->pasted = '';
        $this->counts = null;
    }

    public function clearSelected(): void
    {
        $this->selectedIds = [];
        $this->pasteReport = null;
        $this->counts = null;

        if ($this->type === 'selected') {
            $this->type = 'all';
        }
    }

    public function next(SmsCampaignService $campaigns): void
    {
        SmsAccess::authorize(Auth::user(), SmsAccess::SEND);
        $this->resetErrorBag();

        if ($this->step === 1) {
            if ($this->counts === null) {
                $this->countAudience($campaigns);
            }

            if ($this->counts === null) {
                return;
            }

            if (($this->counts['numbers'] ?? 0) < 1) {
                $this->addError('audience', 'No customer in this audience has a valid number. Change who you are sending to.');

                return;
            }
        }

        if ($this->step === 2) {
            if (! in_array($this->senderId, $campaigns->senderIds(), true)) {
                $this->addError('senderId', 'Choose one of the approved sender IDs.');

                return;
            }

            try {
                $campaigns->checkBody(Auth::user(), trim($this->body));
            } catch (SmsCampaignException $e) {
                $this->addError('body', $e->getMessage());

                return;
            }
        }

        $this->step = min(4, $this->step + 1);
    }

    public function back(): void
    {
        $this->step = max(1, $this->step - 1);
    }

    public function saveTemplate(SmsCampaignService $campaigns): void
    {
        $this->resetErrorBag();

        try {
            $template = $campaigns->saveTemplate(Auth::user(), null, $this->name === '' ? 'Template '.now()->format('d M H:i') : $this->name, $this->body);
            $this->templateId = (string) $template->id;
            $this->dispatch('toast', type: 'success', message: 'Template saved.');
        } catch (SmsCampaignException $e) {
            $this->addError('body', $e->getMessage());
        }
    }

    public function refreshBalance(SmsBalanceService $balance): void
    {
        SmsAccess::authorize(Auth::user(), SmsAccess::BALANCE);
        $balance->current(true);
    }

    public function send(SmsCampaignService $campaigns)
    {
        SmsAccess::authorize(Auth::user(), SmsAccess::SEND);
        $this->resetErrorBag();

        try {
            $campaign = $campaigns->create(Auth::user(), $this->input());
        } catch (SmsCampaignException|SmsAudienceException $e) {
            $this->addError('confirm', $e->getMessage());

            return null;
        }

        session()->flash('status', $campaign->status === 'scheduled' ? 'The campaign is scheduled.' : 'The campaign is queued and will start shortly.');

        return $this->redirect(route('commercial.sms.campaigns.show', $campaign->id), navigate: false);
    }

    // ---------------------------------------------------------------- the page

    public function render(SmsCampaignService $campaigns, SmsAudienceResolver $audiences, SmsBalanceService $balanceService, SmsMessageBuilder $messages)
    {
        SmsAccess::authorize(Auth::user(), SmsAccess::SEND);

        $user = Auth::user();
        $regionIds = SmsAccess::allowedRegionIds($user);
        $districts = DB::table('districts')->whereIn('region_id', $regionIds ?: [0])->orderBy('district_name')->pluck('district_name', 'id');
        $oneDistrict = count($this->districtIds) === 1 ? (int) $this->districtIds[0] : null;

        $inspect = $messages->inspect($this->body);
        $credits = $this->counts ? (int) $this->counts['numbers'] * $inspect['segments'] : null;
        $balance = SmsAccess::allows($user, SmsAccess::BALANCE) ? $balanceService->current() : null;

        $sample = [];
        $example = null;

        if ($this->step >= 3 && $this->counts !== null) {
            try {
                $spec = $audiences->normalise($this->audience());
                $details = $campaigns->mayUseName($user);
                $sample = $audiences->sample($spec, $regionIds, $details);
                $example = $sample !== [] && $this->body !== '' ? $messages->render($this->body, [
                    'name' => (string) ($sample[0]['name'] ?? '(name)'),
                    'account_no' => (string) $sample[0]['account_no'],
                    'balance' => 'GHS 0.00 (shown on each message)',
                ]) : null;
            } catch (SmsAudienceException) {
                $sample = [];
            }
        }

        return view('livewire.commercial.sms.send', [
            'groups' => SmsAudienceResolver::GROUPS,
            'districts' => $districts,
            'routes' => $oneDistrict ? DB::table('commercial_routes')->where('district_id', $oneDistrict)->orderBy('name')->limit(500)->pluck('name', 'id') : collect(),
            'categoryGroups' => CommercialCustomerCategory::GROUPS,
            'statuses' => DB::table('commercial_customer_statuses')->where('meaning_confirmed', true)->orderBy('code')->get(['id', 'code', 'label']),
            'templates' => SmsAccess::scopeRegion(CommercialSmsTemplate::query(), $user)->orderBy('name')->get(['id', 'name']),
            'senders' => $campaigns->senderIds(),
            'inspect' => $inspect,
            'credits' => $credits,
            'covers' => $credits !== null ? $balanceService->covers($credits) : null,
            'balance' => $balance,
            'sample' => $sample,
            'example' => $example,
            'definition' => $this->counts !== null ? $this->describe($audiences) : null,
            'mayUseName' => $campaigns->mayUseName($user),
            'maxRecipients' => (int) config('gwl.commercial_sms_max_recipients'),
            'needsTyping' => ($this->counts['numbers'] ?? 0) >= 20,
        ]);
    }

    protected function describe(SmsAudienceResolver $audiences): string
    {
        try {
            return $audiences->describe($audiences->normalise($this->audience()));
        } catch (SmsAudienceException) {
            return '';
        }
    }
}
