<?php

namespace App\Livewire\Commercial\Sms;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\CommercialSmsTemplate;
use App\Models\Permission;
use App\Services\Commercial\Sms\SmsAccess;
use App\Services\Commercial\Sms\SmsCampaignException;
use App\Services\Commercial\Sms\SmsCampaignService;
use App\Services\Commercial\Sms\SmsMessageBuilder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Message templates of the region: a name and a wording with merge fields. They hold no customer data. Senders manage them. */
class Templates extends Component
{
    use EnforcesModuleAccess;

    #[Locked]
    public ?int $editing = null;

    public string $name = '';

    public string $body = '';

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_COMMERCIAL);
        SmsAccess::authorize(Auth::user(), SmsAccess::SEND);
    }

    public function edit(int $id): void
    {
        SmsAccess::authorize(Auth::user(), SmsAccess::SEND);
        $template = SmsAccess::scopeRegion(CommercialSmsTemplate::query(), Auth::user())->findOrFail($id);
        $this->editing = $template->id;
        $this->name = $template->name;
        $this->body = $template->body;
    }

    public function cancelEdit(): void
    {
        $this->reset('editing', 'name', 'body');
        $this->resetErrorBag();
    }

    public function save(SmsCampaignService $campaigns): void
    {
        $this->resetErrorBag();

        try {
            $campaigns->saveTemplate(Auth::user(), $this->editing, $this->name, $this->body);
        } catch (SmsCampaignException $e) {
            $this->addError('body', $e->getMessage());

            return;
        }

        $this->cancelEdit();
        $this->dispatch('toast', type: 'success', message: 'Template saved.');
    }

    public function delete(int $id, SmsCampaignService $campaigns): void
    {
        $campaigns->deleteTemplate(Auth::user(), $id);

        if ($this->editing === $id) {
            $this->cancelEdit();
        }

        $this->dispatch('toast', type: 'success', message: 'Template deleted.');
    }

    public function render(SmsMessageBuilder $messages)
    {
        SmsAccess::authorize(Auth::user(), SmsAccess::SEND);

        return view('livewire.commercial.sms.templates', [
            'templates' => SmsAccess::scopeRegion(CommercialSmsTemplate::query(), Auth::user())->orderBy('name')->get(),
            'inspect' => $messages->inspect($this->body),
        ]);
    }
}
