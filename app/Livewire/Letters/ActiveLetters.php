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

    public int|string $remarkManagerId = '';

    public int|string $remarkChiefManagerId = '';

    public string $secretaryRemarkContent = '';

    public ?int $editingRemarkId = null;

    public string $editingRemarkContent = '';

    public int|string $editingRemarkManagerId = '';

    public int|string $editingRemarkChiefManagerId = '';

    public string $editingSecretaryRemarkContent = '';

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
        $this->dispatch('toast', type: 'success', message: $this->flashMessage);
        $this->fillEditForm($letter->fresh());
    }

    public function dispatchLetter(LetterWorkflowService $workflow): void
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
        $this->flashMessage = 'Letter dispatched to '.$recipient->full_name.'.';
        $this->dispatch('toast', type: 'success', message: $this->flashMessage);
    }

    public function closeLetter(LetterWorkflowService $workflow): void
    {
        $workflow->close($this->selectedLetter($workflow), $this->requireEmployee());
        $this->tab = 'closed';
        $this->flashMessage = 'Letter closed.';
        $this->dispatch('toast', type: 'success', message: $this->flashMessage);
    }

    public function reopenLetter(LetterWorkflowService $workflow): void
    {
        $workflow->reopen($this->selectedLetter($workflow), $this->requireEmployee());
        $this->tab = 'active';
        $this->flashMessage = 'Letter reopened.';
        $this->dispatch('toast', type: 'success', message: $this->flashMessage);
    }

    public function addRemark(LetterWorkflowService $workflow): void
    {
        abort_if(! $this->canRemark(), 403);

        $employee = $this->requireEmployee();

        $this->validate([
            'remarkManagerId' => ['nullable', 'exists:employees,id'],
            'remarkChiefManagerId' => ['nullable', 'exists:employees,id'],
            'remarkContent' => ['nullable', 'string', 'max:4000'],
            'secretaryRemarkContent' => ['nullable', 'string', 'max:4000'],
        ]);

        $remarkContent = trim($this->remarkContent);
        $secretaryRemarkContent = trim($this->secretaryRemarkContent);
        $hasManagerRemark = $remarkContent !== '';
        $hasSecretaryRemark = $secretaryRemarkContent !== '';
        $hasManager = filled($this->remarkManagerId);
        $hasChiefManager = filled($this->remarkChiefManagerId);

        if (! $hasManagerRemark && ! $hasSecretaryRemark) {
            $this->addError('remarkContent', 'Enter manager/chief manager remarks or secretary remarks.');
            $this->addError('secretaryRemarkContent', 'Enter manager/chief manager remarks or secretary remarks.');
        }

        if ($hasManagerRemark && $hasManager && $hasChiefManager) {
            $this->addError('remarkManagerId', 'Select either a manager or a chief manager, not both.');
            $this->addError('remarkChiefManagerId', 'Select either a manager or a chief manager, not both.');
        }

        if ($hasManagerRemark && ! $hasManager && ! $hasChiefManager) {
            $this->addError('remarkManagerId', 'Select a manager or a chief manager before adding manager remarks.');
        }

        if (! $hasManagerRemark && ($hasManager || $hasChiefManager)) {
            $this->addError('remarkContent', 'Enter manager/chief manager remarks for the selected reviewer.');
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $manager = null;
        $chiefManager = null;

        if ($hasManager) {
            $manager = $this->resolveRegionalRemarkReviewer($workflow, $employee, $this->remarkManagerId, 'remarkManagerId', 'manager');
        }

        if ($hasChiefManager) {
            $chiefManager = $this->resolveRegionalRemarkReviewer($workflow, $employee, $this->remarkChiefManagerId, 'remarkChiefManagerId', 'chief');
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $workflow->addRemark($this->selectedLetter($workflow), $employee, [
            'manager_id' => $manager?->id,
            'chief_manager_id' => $chiefManager?->id,
            'remark_content' => $hasManagerRemark ? $remarkContent : '',
            'secretary_remark_content' => $hasSecretaryRemark ? $secretaryRemarkContent : null,
        ]);

        $this->resetRemarkForm();
        $this->flashMessage = 'Remark added.';
        $this->dispatch('toast', type: 'success', message: $this->flashMessage);
    }

    public function startEditRemark(int $remarkId): void
    {
        $remark = LetterRemark::query()->findOrFail($remarkId);
        abort_if($remark->author_id !== $this->requireEmployee()->id, 403);

        $this->editingRemarkId = $remark->id;
        $this->editingRemarkContent = $remark->remark_content;
        $this->editingRemarkManagerId = $remark->manager_id ?: '';
        $this->editingRemarkChiefManagerId = $remark->chief_manager_id ?: '';
        $this->editingSecretaryRemarkContent = $remark->secretary_remark_content ?? '';
    }

    public function updateRemark(LetterWorkflowService $workflow): void
    {
        abort_if(! $this->editingRemarkId, 404);

        $employee = $this->requireEmployee();

        $this->validate([
            'editingRemarkManagerId' => ['nullable', 'exists:employees,id'],
            'editingRemarkChiefManagerId' => ['nullable', 'exists:employees,id'],
            'editingRemarkContent' => ['nullable', 'string', 'max:4000'],
            'editingSecretaryRemarkContent' => ['nullable', 'string', 'max:4000'],
        ]);

        $remarkContent = trim($this->editingRemarkContent);
        $secretaryRemarkContent = trim($this->editingSecretaryRemarkContent);
        $hasManagerRemark = $remarkContent !== '';
        $hasSecretaryRemark = $secretaryRemarkContent !== '';
        $hasManager = filled($this->editingRemarkManagerId);
        $hasChiefManager = filled($this->editingRemarkChiefManagerId);

        if (! $hasManagerRemark && ! $hasSecretaryRemark) {
            $this->addError('editingRemarkContent', 'Enter manager/chief manager remarks or secretary remarks.');
            $this->addError('editingSecretaryRemarkContent', 'Enter manager/chief manager remarks or secretary remarks.');
        }

        if ($hasManagerRemark && $hasManager && $hasChiefManager) {
            $this->addError('editingRemarkManagerId', 'Select either a manager or a chief manager, not both.');
            $this->addError('editingRemarkChiefManagerId', 'Select either a manager or a chief manager, not both.');
        }

        if ($hasManagerRemark && ! $hasManager && ! $hasChiefManager) {
            $this->addError('editingRemarkManagerId', 'Select a manager or a chief manager before adding manager remarks.');
        }

        if (! $hasManagerRemark && ($hasManager || $hasChiefManager)) {
            $this->addError('editingRemarkContent', 'Enter manager/chief manager remarks for the selected reviewer.');
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $manager = null;
        $chiefManager = null;

        if ($hasManager) {
            $manager = $this->resolveRegionalRemarkReviewer($workflow, $employee, $this->editingRemarkManagerId, 'editingRemarkManagerId', 'manager');
        }

        if ($hasChiefManager) {
            $chiefManager = $this->resolveRegionalRemarkReviewer($workflow, $employee, $this->editingRemarkChiefManagerId, 'editingRemarkChiefManagerId', 'chief');
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $remark = LetterRemark::query()->findOrFail($this->editingRemarkId);
        $workflow->updateRemark($remark, $employee, [
            'manager_id' => $manager?->id,
            'chief_manager_id' => $chiefManager?->id,
            'remark_content' => $hasManagerRemark ? $remarkContent : '',
            'secretary_remark_content' => $hasSecretaryRemark ? $secretaryRemarkContent : null,
        ]);

        $this->resetEditingRemarkForm();
        $this->flashMessage = 'Remark updated.';
        $this->dispatch('toast', type: 'success', message: $this->flashMessage);
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
        $this->dispatch('toast', type: 'success', message: $this->flashMessage);
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
                'managerOptions' => [],
                'chiefManagerOptions' => [],
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
                        ->where('subject', 'like', '%'.$this->search.'%')
                        ->orWhere('ref_no', 'like', '%'.$this->search.'%')
                        ->orWhere('sn_number', 'like', '%'.$this->search.'%')
                        ->orWhere('company_sender', 'like', '%'.$this->search.'%')
                        ->orWhereHas('memoSender', fn ($senderQuery) => $senderQuery->where('full_name', 'like', '%'.$this->search.'%'));
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
                    'remarks.manager',
                    'remarks.chiefManager',
                ])
                ->find($this->selectedLetterId)
            : null;

        return view('livewire.letters.active-letters', [
            'missingEmployee' => false,
            'letters' => $letters,
            'selectedLetter' => $selectedLetter,
            'secretaries' => $workflow->secretaryQuery($this->secretarySearch)->limit(30)->get(),
            'managerOptions' => $this->employeeOptions($workflow->regionalManagersQuery($employee)->with(['department', 'region'])->get()),
            'chiefManagerOptions' => $this->employeeOptions($workflow->regionalChiefManagersQuery($employee)->with(['department', 'region'])->get()),
            'senders' => Employee::query()
                ->active()
                ->visibleInErp()
                ->when($this->editSenderSearch, function ($query) {
                    $query->where(function ($searchQuery) {
                        $searchQuery
                            ->where('full_name', 'like', '%'.$this->editSenderSearch.'%')
                            ->orWhere('staff_id', 'like', '%'.$this->editSenderSearch.'%');
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

    protected function resetRemarkForm(): void
    {
        $this->remarkManagerId = '';
        $this->remarkChiefManagerId = '';
        $this->remarkContent = '';
        $this->secretaryRemarkContent = '';
    }

    protected function resetEditingRemarkForm(): void
    {
        $this->editingRemarkId = null;
        $this->editingRemarkManagerId = '';
        $this->editingRemarkChiefManagerId = '';
        $this->editingRemarkContent = '';
        $this->editingSecretaryRemarkContent = '';
    }

    protected function resetDetailInputs(): void
    {
        $this->resetRemarkForm();
        $this->resetEditingRemarkForm();
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

    protected function resolveRegionalRemarkReviewer(
        LetterWorkflowService $workflow,
        Employee $employee,
        int|string $reviewerId,
        string $field,
        string $type
    ): ?Employee {
        $reviewer = $type === 'chief'
            ? $workflow->regionalChiefManagersQuery($employee)->find($reviewerId)
            : $workflow->regionalManagersQuery($employee)->find($reviewerId);

        if (! $reviewer) {
            $this->addError($field, $type === 'chief'
                ? 'Select a chief manager in your region.'
                : 'Select a manager in your region.');
        }

        return $reviewer;
    }

    protected function employeeOptions($employees): array
    {
        return $employees
            ->map(fn (Employee $employee) => [
                'value' => $employee->id,
                'label' => $employee->full_name,
                'description' => collect([
                    $employee->staff_id,
                    $employee->department?->department_name,
                    $employee->region?->region_name,
                ])->filter()->join(' / '),
            ])
            ->all();
    }
}
