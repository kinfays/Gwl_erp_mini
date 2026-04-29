<?php

namespace App\Livewire\Letters;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\Employee;
use App\Models\LetterRemark;
use App\Models\MailLetter;
use App\Services\Letters\LetterWorkflowService;
use Livewire\Component;
use Livewire\WithPagination;

class ActiveLetters extends Component
{
    use EnforcesModuleAccess;
    use WithPagination;

    public string $tab = 'active';
    public string $typeFilter = '';
    public string $search = '';
    public int $perPage = 15;

    public ?int $selectedLetterId = null;
    public string $detailTab = 'remarks';
    public bool $confirmPrompt = false;
    public string $flashMessage = '';

    public string $remarkContent = '';
    public ?int $editingRemarkId = null;
    public string $editingRemarkContent = '';

    public string $secretarySearch = '';
    public int|string $dispatchToId = '';

    public string $editSubject = '';
    public string $editRefNo = '';
    public string $editType = 'Internal';
    public int|string $editMemoSenderId = '';
    public string $editCompanySender = '';
    public string $editDateOnLetter = '';
    public string $editSenderSearch = '';

    public function mount(LetterWorkflowService $workflow): void
    {
        $this->enforceLivewireModule('letters');

        if (request()->boolean('closed') || request()->routeIs('letters.closed')) {
            $this->tab = 'closed';
        }

        if ($letterId = request()->integer('letter')) {
            $this->openLetter($letterId, request()->boolean('prompt'), $workflow);
        }
    }

    public function updating($name): void
    {
        if (in_array($name, ['tab', 'typeFilter', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab === 'closed' ? 'closed' : 'active';
        $this->resetPage();
    }

    public function openLetter(int $letterId, bool $fromNotification = false, ?LetterWorkflowService $workflow = null): void
    {
        $workflow ??= app(LetterWorkflowService::class);
        $employee = $this->requireEmployee();
        $letter = $this->findVisibleLetter($letterId, $workflow, $employee);

        $this->selectedLetterId = $letter->id;
        $this->flashMessage = '';

        if ($fromNotification && $workflow->pendingIncomingRoute($letter, $employee)) {
            $this->confirmPrompt = true;
            return;
        }

        if ($workflow->pendingIncomingRoute($letter, $employee)) {
            $this->confirmPrompt = true;
            return;
        }

        $this->confirmPrompt = false;
        $workflow->markInReview($letter, $employee);
        $this->fillEditForm($letter->fresh());
    }

    public function closePanel(): void
    {
        $this->selectedLetterId = null;
        $this->confirmPrompt = false;
        $this->resetDetailInputs();
    }

    public function confirmHardcopy(LetterWorkflowService $workflow): void
    {
        $letter = $this->selectedLetter($workflow);
        $employee = $this->requireEmployee();

        $workflow->confirmHardcopy($letter, $employee);
        $this->confirmPrompt = false;
        $this->flashMessage = 'Hardcopy receipt confirmed.';
        $this->fillEditForm($letter->fresh());
    }

    public function dispatch(LetterWorkflowService $workflow): void
    {
        abort_if(! $this->canForward(), 403);

        $employee = $this->requireEmployee();
        $letter = $this->selectedLetter($workflow);

        $this->validate([
            'dispatchToId' => ['required', 'exists:employees,id'],
        ]);

        $recipient = $workflow->secretaryQuery()->findOrFail($this->dispatchToId);
        $workflow->dispatch($letter, $employee, $recipient);

        $this->dispatchToId = '';
        $this->secretarySearch = '';
        $this->flashMessage = 'Letter dispatched to ' . $recipient->full_name . '.';
    }

    public function closeLetter(LetterWorkflowService $workflow): void
    {
        $workflow->close($this->selectedLetter($workflow), $this->requireEmployee());
        $this->tab = 'closed';
        $this->flashMessage = 'Letter closed.';
    }

    public function reopenLetter(LetterWorkflowService $workflow): void
    {
        $workflow->reopen($this->selectedLetter($workflow), $this->requireEmployee());
        $this->tab = 'active';
        $this->flashMessage = 'Letter reopened.';
    }

    public function addRemark(LetterWorkflowService $workflow): void
    {
        abort_if(! $this->canRemark(), 403);

        $this->validate([
            'remarkContent' => ['required', 'string', 'max:4000'],
        ]);

        $workflow->addRemark($this->selectedLetter($workflow), $this->requireEmployee(), $this->remarkContent);
        $this->remarkContent = '';
        $this->flashMessage = 'Remark added.';
    }

    public function startEditRemark(int $remarkId): void
    {
        $remark = LetterRemark::query()->findOrFail($remarkId);
        abort_if($remark->author_id !== $this->requireEmployee()->id, 403);

        $this->editingRemarkId = $remark->id;
        $this->editingRemarkContent = $remark->remark_content;
    }

    public function updateRemark(LetterWorkflowService $workflow): void
    {
        $this->validate([
            'editingRemarkContent' => ['required', 'string', 'max:4000'],
        ]);

        $remark = LetterRemark::query()->findOrFail($this->editingRemarkId);
        $workflow->updateRemark($remark, $this->requireEmployee(), $this->editingRemarkContent);

        $this->editingRemarkId = null;
        $this->editingRemarkContent = '';
        $this->flashMessage = 'Remark updated.';
    }

    public function updateLetter(LetterWorkflowService $workflow): void
    {
        $letter = $this->selectedLetter($workflow);
        abort_if($letter->created_by_id !== $this->requireEmployee()->id, 403);

        $validated = $this->validate([
            'editSubject' => ['required', 'string', 'max:255'],
            'editRefNo' => ['nullable', 'string', 'max:255'],
            'editType' => ['required', 'in:Internal,External'],
            'editMemoSenderId' => ['required_if:editType,Internal', 'nullable', 'exists:employees,id'],
            'editCompanySender' => ['required_if:editType,External', 'nullable', 'string', 'max:500'],
            'editDateOnLetter' => ['required', 'date'],
        ]);

        $workflow->updateLetter($letter, $this->requireEmployee(), [
            'subject' => $validated['editSubject'],
            'ref_no' => $validated['editRefNo'],
            'type' => $validated['editType'],
            'memo_sender_id' => $validated['editMemoSenderId'],
            'company_sender' => $validated['editCompanySender'],
            'date_on_letter' => $validated['editDateOnLetter'],
        ]);

        $this->flashMessage = 'Letter details updated.';
    }

    public function render(LetterWorkflowService $workflow)
    {
        $employee = $this->employee();

        if (! $employee) {
            return view('livewire.letters.active-letters', [
                'missingEmployee' => true,
                'letters' => collect(),
                'selectedLetter' => null,
                'secretaries' => collect(),
                'senders' => collect(),
            'canRemark' => false,
                'canForward' => false,
            ]);
        }

        $letters = $workflow->visibleLettersQuery($employee)
            ->with([
                'region',
                'memoSender',
                'creator',
                'statusLogs.secretariat',
                'routingHistories.fromSecretariat',
                'routingHistories.toSecretariat',
            ])
            ->when($this->tab === 'active', function ($query) use ($employee) {
                $query->whereHas('statusLogs', fn ($statusQuery) => $statusQuery
                    ->where('secretariat_id', $employee->id)
                    ->where('is_closed', false));
            })
            ->when($this->tab === 'closed', function ($query) use ($employee) {
                $query->whereHas('statusLogs', fn ($statusQuery) => $statusQuery
                    ->where('secretariat_id', $employee->id)
                    ->where('is_closed', true));
            })
            ->when($this->typeFilter, fn ($query) => $query->where('type', $this->typeFilter))
            ->when($this->search, function ($query) {
                $query->where(function ($searchQuery) {
                    $searchQuery
                        ->where('subject', 'like', '%' . $this->search . '%')
                        ->orWhere('ref_no', 'like', '%' . $this->search . '%')
                        ->orWhere('sn_number', 'like', '%' . $this->search . '%')
                        ->orWhere('company_sender', 'like', '%' . $this->search . '%')
                        ->orWhereHas('memoSender', fn ($senderQuery) => $senderQuery->where('full_name', 'like', '%' . $this->search . '%'));
                });
            })
            ->latest()
            ->paginate($this->perPage);

        $selectedLetter = $this->selectedLetterId
            ? MailLetter::query()
                ->with([
                    'region',
                    'memoSender',
                    'creator',
                    'statusLogs.secretariat',
                    'routingHistories.fromSecretariat',
                    'routingHistories.toSecretariat',
                    'remarks.author',
                ])
                ->find($this->selectedLetterId)
            : null;

        return view('livewire.letters.active-letters', [
            'missingEmployee' => false,
            'letters' => $letters,
            'selectedLetter' => $selectedLetter,
            'secretaries' => $workflow->secretaryQuery($this->secretarySearch)->limit(30)->get(),
            'senders' => Employee::query()
                ->active()
                ->visibleInErp()
                ->when($this->editSenderSearch, function ($query) {
                    $query->where(function ($searchQuery) {
                        $searchQuery
                            ->where('full_name', 'like', '%' . $this->editSenderSearch . '%')
                            ->orWhere('staff_id', 'like', '%' . $this->editSenderSearch . '%');
                    });
                })
                ->orderBy('full_name')
                ->limit(30)
                ->get(),
            'canRemark' => $this->canRemark(),
            'canForward' => $this->canForward(),
            'employee' => $employee,
            'workflow' => $workflow,
        ]);
    }

    protected function selectedLetter(LetterWorkflowService $workflow): MailLetter
    {
        return $this->findVisibleLetter($this->selectedLetterId, $workflow, $this->requireEmployee());
    }

    protected function findVisibleLetter(?int $letterId, LetterWorkflowService $workflow, Employee $employee): MailLetter
    {
        abort_if(! $letterId, 404);

        return $workflow->visibleLettersQuery($employee)->findOrFail($letterId);
    }

    protected function fillEditForm(MailLetter $letter): void
    {
        $this->editSubject = $letter->subject;
        $this->editRefNo = $letter->ref_no ?? '';
        $this->editType = $letter->type;
        $this->editMemoSenderId = $letter->memo_sender_id ?: '';
        $this->editCompanySender = $letter->company_sender ?? '';
        $this->editDateOnLetter = optional($letter->date_on_letter)->toDateString() ?? '';
        $this->editSenderSearch = $letter->memoSender?->full_name ?? '';
    }

    protected function resetDetailInputs(): void
    {
        $this->remarkContent = '';
        $this->editingRemarkId = null;
        $this->editingRemarkContent = '';
        $this->dispatchToId = '';
        $this->secretarySearch = '';
        $this->flashMessage = '';
    }

    protected function employee(): ?Employee
    {
        $user = auth()->user();

        return $user?->employee ?? $user?->employeeByStaffId;
    }

    protected function requireEmployee(): Employee
    {
        $employee = $this->employee();

        abort_if(! $employee, 403, 'Your user account is not linked to an employee record.');

        return $employee;
    }

    protected function canRemark(): bool
    {
        $user = auth()->user();

        return $user && ($user->hasRoles('super_admin') || $user->hasPermission('letters.remark'));
    }

    protected function canForward(): bool
    {
        $user = auth()->user();

        return $user && ($user->hasRoles('super_admin') || $user->hasPermission('letters.forward'));
    }
}
