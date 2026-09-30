<?php

namespace App\Livewire\Letters;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\Employee;
use App\Models\LetterRemark;
use App\Models\LetterScan;
use App\Models\MailLetter;
use App\Models\RoutingHistory;
use App\Services\Letters\LetterScanService;
use App\Services\Letters\LetterWorkflowService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class ActiveLetters extends Component
{
    use EnforcesModuleAccess;
    use WithFileUploads;
    use WithPagination;

    public string $tab = 'active';

    public string $typeFilter = '';

    public string $search = '';

    /** '' | 'awaiting' (Awaiting my confirmation) | 'ready' (Ready to dispatch) - applied in SQL. */
    public string $quickFilter = '';

    public int $perPage = 15;

    /**
     * Letter ids ticked on the current page. Client-controlled, so every bulk action re-resolves them through
     * visibleLettersQuery + deskState and acts only on the rows the actor may act on.
     *
     * @var array<int, int|string>
     */
    public array $selected = [];

    public bool $bulkDispatchOpen = false;

    /**
     * The letters the open drawer lists, frozen when it opened, so what the user confirms is what is dispatched. The
     * service re-checks every one (visibility and holder state) and refuses the whole batch if any went stale.
     *
     * @var array<int, int>
     */
    public array $bulkLetterIds = [];

    /** How many ticked letters were left out of the drawer because they cannot be dispatched. */
    public int $bulkSkipped = 0;

    public int|string $bulkDispatchToId = '';

    public string $bulkSearch = '';

    public string $bulkNote = '';

    public ?int $lastBatchId = null;

    public string $lastBatchNo = '';

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

    public string $recipientSearch = '';

    public int|string $dispatchToId = '';

    /** Picker chips: 'mine' (my location), 'head_office' or 'any'. */
    public string $dispatchScope = 'mine';

    public string $bulkScope = 'mine';

    /** "Deliver to addressee": who took the hardcopy (a staff member, or an outside party by name), when, and a note. */
    public string $deliverMode = 'employee';

    public int|string $deliverEmployeeId = '';

    public string $deliverEmployeeSearch = '';

    public string $deliverName = '';

    public string $deliverAt = '';

    public string $deliverNote = '';

    /** Scans tab (only with gwl.letters_scans_enabled): files picked, what they are, a note, and a scan being voided. */
    public array $scans = [];

    public string $scanKind = 'original';

    public string $scanNote = '';

    public ?int $voidingScanId = null;

    public string $voidReason = '';

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
        if (in_array($name, ['tab', 'typeFilter', 'search', 'quickFilter'], true)) {
            $this->resetPage();
            $this->clearSelection();
        }
    }

    /** Any page change (next, previous, goto, resetPage) drops the ticks: selection never spans pages. */
    public function updatingPaginators(): void
    {
        $this->clearSelection();
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab === 'closed' ? 'closed' : 'active';
        $this->resetPage();
        $this->clearSelection();
    }

    public function setQuickFilter(string $filter): void
    {
        // Clicking the active filter turns it off.
        $filter = in_array($filter, ['awaiting', 'ready'], true) && $filter !== $this->quickFilter ? $filter : '';

        $this->quickFilter = $filter;
        $this->resetPage();
        $this->clearSelection();
    }

    public function clearSelection(): void
    {
        $this->selected = [];
        $this->closeBulkDispatch();
    }

    /** Header checkbox: tick every row on this page the actor can act on, or untick them all if they already are. */
    public function togglePage(LetterWorkflowService $workflow): void
    {
        $employee = $this->requireEmployee();
        $page = $this->lettersQuery($workflow, $employee)->paginate($this->perPage);
        $desk = $workflow->deskState($page->getCollection(), $employee);
        $actionable = $this->actionableIds($desk);

        $current = $this->selectedIds();
        $allTicked = $actionable !== [] && array_diff($actionable, $current) === [];

        $this->selected = $allTicked ? [] : $actionable;
    }

    /** Confirm the hardcopy of every ticked letter that is awaiting the actor's confirmation. */
    public function confirmSelected(LetterWorkflowService $workflow): void
    {
        $employee = $this->requireEmployee();
        [$letters, $desk] = $this->selectionState($workflow, $employee);

        $confirmable = $letters->filter(fn (MailLetter $letter) => $desk[$letter->id]['pendingRoute'] !== null)->pluck('id')->all();

        if ($confirmable === []) {
            $this->pruneSelection($letters, $desk);
            $this->failWith(new \RuntimeException('None of the selected letters is awaiting your confirmation.'));

            return;
        }

        try {
            $confirmed = $workflow->confirmHardcopies($employee, $confirmable);
        } catch (\RuntimeException $e) {
            $this->pruneSelection($letters, $desk);
            $this->failWith($e);

            return;
        }

        $skipped = count($this->selectedIds()) - $confirmed;
        $this->clearSelection();
        $this->flashMessage = '';
        $this->dispatch('toast', type: 'success', message: 'Confirmed hardcopy receipt for '.$confirmed.' '.Str::plural('letter', $confirmed).'.'.($skipped > 0 ? " {$skipped} selected ".Str::plural('letter', $skipped).' skipped (not awaiting your confirmation).' : ''));
    }

    public function openBulkDispatch(LetterWorkflowService $workflow): void
    {
        abort_if(! $this->canForward(), 403);

        $employee = $this->requireEmployee();
        [$letters, $desk] = $this->selectionState($workflow, $employee);
        $dispatchable = $letters->filter(fn (MailLetter $letter) => $desk[$letter->id]['canDispatch']);

        if ($dispatchable->isEmpty()) {
            $this->pruneSelection($letters, $desk);
            $this->failWith(new \RuntimeException('None of the selected letters can be dispatched by you right now.'));

            return;
        }

        $this->selectedLetterId = null;
        $this->bulkLetterIds = $dispatchable->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $this->bulkSkipped = count($this->selectedIds()) - count($this->bulkLetterIds);
        $this->bulkDispatchOpen = true;
    }

    public function closeBulkDispatch(): void
    {
        $this->bulkDispatchOpen = false;
        $this->bulkLetterIds = [];
        $this->bulkSkipped = 0;
        $this->bulkDispatchToId = '';
        $this->bulkSearch = '';
        $this->bulkNote = '';
    }

    /** The last step: record who took the hardcopy and when. Only the current, confirmed holder can (the service decides). */
    public function deliverLetter(LetterWorkflowService $workflow): void
    {
        $employee = $this->requireEmployee();
        $letter = $this->selectedLetter($workflow);

        $this->validate([
            'deliverMode' => ['required', 'in:employee,name'],
            'deliverEmployeeId' => ['required_if:deliverMode,employee', 'nullable', 'exists:employees,id'],
            'deliverName' => ['required_if:deliverMode,name', 'nullable', 'string', 'max:255'],
            'deliverAt' => ['nullable', 'date'],
            'deliverNote' => ['nullable', 'string', 'max:500'],
        ], [
            'deliverEmployeeId.required_if' => 'Choose the staff member who received the letter.',
            'deliverName.required_if' => 'Type the name of the person who received the letter.',
        ]);

        try {
            $delivery = $workflow->deliver($letter, $employee, [
                'delivered_to_employee_id' => $this->deliverMode === 'employee' ? $this->deliverEmployeeId : null,
                'delivered_to_name' => $this->deliverMode === 'name' ? $this->deliverName : null,
                'delivered_at' => $this->deliverAt,
                'note' => $this->deliverNote,
            ]);
        } catch (\RuntimeException $e) {
            $this->failWith($e);

            return;
        }

        // The letter is closed now: it moves to the Closed tab, and ticks never follow a letter to another list.
        $this->tab = 'closed';
        $this->resetPage();
        $this->clearSelection();
        $this->resetDeliverForm();
        $this->detailTab = 'remarks';
        $this->flashMessage = 'Delivered to '.$delivery->addresseeName().'. Letter closed.';
        $this->dispatch('toast', type: 'success', message: $this->flashMessage);
    }

    public function dispatchSelected(LetterWorkflowService $workflow): void
    {
        abort_if(! $this->canForward(), 403);

        $employee = $this->requireEmployee();

        $this->validate([
            'bulkDispatchToId' => ['required', 'exists:employees,id'],
            'bulkNote' => ['nullable', 'string', 'max:500'],
        ]);

        // Eligibility is the service's call (it re-checks), so a crafted id gets the same message as a stale one.
        $recipient = Employee::query()->findOrFail($this->bulkDispatchToId);
        $skipped = $this->bulkSkipped;

        try {
            $batch = $workflow->dispatchBatch($employee, $recipient, $this->bulkLetterIds, $this->bulkNote);
        } catch (\RuntimeException $e) {
            // The page changed under the drawer (another tab, another device), or a rule such as the size limit
            // applies: show why, drop what is no longer actionable and let the user look at the list again.
            // Nothing was dispatched.
            $this->closeBulkDispatch();
            $this->pruneSelection(...$this->selectionState($workflow, $employee));
            $this->failWith($e);

            return;
        }

        $this->clearSelection();
        $this->lastBatchId = $batch->id;
        $this->lastBatchNo = $batch->batch_no;
        $this->flashMessage = '';
        $this->dispatch('toast', type: 'success', message: "Transmittal {$batch->batch_no} created: {$batch->letters_count} ".Str::plural('letter', $batch->letters_count).' dispatched to '.$recipient->full_name.'.'.($skipped > 0 ? " {$skipped} selected ".Str::plural('letter', $skipped).' skipped (not ready to dispatch).' : ''));
    }

    public function dismissBatchNotice(): void
    {
        $this->lastBatchId = null;
        $this->lastBatchNo = '';
    }

    /** $fromNotification is kept for the callers that pass it (notification links, the Confirm Hardcopy button); both paths behave the same. */
    public function openLetter(int $letterId, bool $fromNotification = false, ?LetterWorkflowService $workflow = null): void
    {
        $workflow ??= app(LetterWorkflowService::class);
        $employee = $this->requireEmployee();
        $letter = $this->findVisibleLetter($letterId, $workflow, $employee);

        $this->selectedLetterId = $letter->id;
        $this->flashMessage = '';

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

        try {
            $workflow->confirmHardcopy($letter, $employee);
        } catch (\RuntimeException $e) {
            $this->failWith($e);

            return;
        }

        $this->confirmPrompt = false;
        $this->flashMessage = 'Hardcopy receipt confirmed.';
        $this->dispatch('toast', type: 'success', message: $this->flashMessage);
        $this->fillEditForm($letter->fresh());
        $this->pruneStaleSelection($workflow);
    }

    public function dispatchLetter(LetterWorkflowService $workflow): void
    {
        abort_if(! $this->canForward(), 403);

        $employee = $this->requireEmployee();
        $letter = $this->selectedLetter($workflow);

        $this->validate([
            'dispatchToId' => ['required', 'exists:employees,id'],
        ]);

        $recipient = Employee::query()->findOrFail($this->dispatchToId);

        try {
            $workflow->dispatch($letter, $employee, $recipient);
        } catch (\RuntimeException $e) {
            $this->failWith($e);

            return;
        }

        $this->dispatchToId = '';
        $this->recipientSearch = '';
        $this->flashMessage = 'Letter dispatched to '.$recipient->full_name.'.';
        $this->dispatch('toast', type: 'success', message: $this->flashMessage);
        $this->pruneStaleSelection($workflow);
    }

    /** Attach the picked files to the open letter. Only the current, confirmed holder can (the service decides). */
    public function uploadScans(LetterScanService $scanService, LetterWorkflowService $workflow): void
    {
        abort_unless($scanService->enabled(), 404);

        $employee = $this->requireEmployee();
        $letter = $this->selectedLetter($workflow);

        $this->validate([
            'scans' => ['required', 'array', 'min:1', 'max:'.max(1, (int) config('gwl.letters_scan_max_files', 10))],
            'scans.*' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:'.max(1, (int) config('gwl.letters_scan_max_kb', 10240))],
            'scanKind' => ['required', Rule::in(array_keys(LetterScan::KINDS))],
            'scanNote' => ['nullable', 'string', 'max:255'],
        ], [
            'scans.required' => 'Choose at least one file.',
            'scans.*.mimes' => 'Scans must be PDF, JPG or PNG files.',
            'scans.*.max' => 'A file is too large (the limit is '.round(max(1, (int) config('gwl.letters_scan_max_kb', 10240)) / 1024, 1).' MB each).',
        ]);

        $added = 0;
        $problems = [];

        foreach ($this->scans as $file) {
            try {
                $scanService->add($letter, $employee, $file, $this->scanKind, $this->scanNote);
                $added++;
            } catch (\RuntimeException $e) {
                $problems[] = $file->getClientOriginalName().': '.$e->getMessage();
            }
        }

        $this->scans = [];

        if ($added > 0) {
            $this->scanNote = '';
            $this->dispatch('toast', type: 'success', message: $added.' '.\Illuminate\Support\Str::plural('scan', $added).' attached.');
        }

        if ($problems !== []) {
            $this->failWith(new \RuntimeException(implode(' ', array_slice($problems, 0, 3))));
        }
    }

    public function startVoidScan(int $scanId): void
    {
        $this->voidingScanId = $scanId;
        $this->voidReason = '';
        $this->resetErrorBag('voidReason');
    }

    public function cancelVoidScan(): void
    {
        $this->voidingScanId = null;
        $this->voidReason = '';
    }

    /** Hide a scan (the file is kept). The scan is looked up through the letters the actor can see. */
    public function voidScan(LetterScanService $scanService, LetterWorkflowService $workflow): void
    {
        abort_unless($scanService->enabled(), 404);
        abort_if($this->voidingScanId === null, 404);

        $employee = $this->requireEmployee();
        $letter = $this->selectedLetter($workflow);
        $scan = $letter->scans()->active()->findOrFail($this->voidingScanId);

        $this->validate(['voidReason' => ['required', 'string', 'max:255']], ['voidReason.required' => 'Give a reason for voiding this scan.']);

        try {
            $scanService->void($scan, $employee, $this->voidReason);
        } catch (\RuntimeException $e) {
            $this->failWith($e);

            return;
        }

        $this->cancelVoidScan();
        $this->dispatch('toast', type: 'success', message: 'Scan voided. It is hidden; the file is kept.');
    }

    /** Take back a single dispatch the recipient has not confirmed (the Recall link on the sender's Dispatched row). */
    public function recallHop(LetterWorkflowService $workflow, int $hopId): void
    {
        abort_if(! $this->canForward(), 403);

        $employee = $this->requireEmployee();
        $hop = RoutingHistory::query()->with('letter')->where('from_secretariat_id', $employee->id)->find($hopId);

        if (! $hop) {
            $this->failWith(new \RuntimeException('That hand-over was not found.'));

            return;
        }

        try {
            $workflow->recall($hop, $employee);
        } catch (\RuntimeException $e) {
            $this->failWith($e);

            return;
        }

        $this->flashMessage = '';
        $this->dispatch('toast', type: 'success', message: ($hop->letter?->sn_number ?? 'Letter').' recalled. It is back on your desk.');
        $this->pruneStaleSelection($workflow);
    }

    public function closeLetter(LetterWorkflowService $workflow): void
    {
        try {
            $workflow->close($this->selectedLetter($workflow), $this->requireEmployee());
        } catch (\RuntimeException $e) {
            $this->failWith($e);

            return;
        }

        // The tab changes under the page: same rule as setTab(), ticks never follow a letter to another list.
        $this->tab = 'closed';
        $this->resetPage();
        $this->clearSelection();
        $this->flashMessage = 'Letter closed.';
        $this->dispatch('toast', type: 'success', message: $this->flashMessage);
    }

    public function reopenLetter(LetterWorkflowService $workflow): void
    {
        try {
            $workflow->reopen($this->selectedLetter($workflow), $this->requireEmployee());
        } catch (\RuntimeException $e) {
            $this->failWith($e);

            return;
        }

        $this->tab = 'active';
        $this->resetPage();
        $this->clearSelection();
        $this->flashMessage = 'Letter reopened.';
        $this->dispatch('toast', type: 'success', message: $this->flashMessage);
    }

    public function addRemark(LetterWorkflowService $workflow): void
    {
        abort_if(! $this->canRemark(), 403);

        $employee = $this->requireEmployee();
        $tier = $workflow->reviewerTier($employee);

        // A manager or chief manager holding the letter is the reviewer: whatever the client sent, the reviewer is them
        // and there is no secretary field. (The service enforces the same.)
        if ($tier !== null) {
            $this->remarkManagerId = $tier === 'manager' ? $employee->id : '';
            $this->remarkChiefManagerId = $tier === 'chief' ? $employee->id : '';
            $this->secretaryRemarkContent = '';
        }

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
            $manager = $tier === 'manager'
                ? $employee
                : $this->resolveRegionalRemarkReviewer($workflow, $employee, $this->remarkManagerId, 'remarkManagerId', 'manager');
        }

        if ($hasChiefManager) {
            $chiefManager = $tier === 'chief'
                ? $employee
                : $this->resolveRegionalRemarkReviewer($workflow, $employee, $this->remarkChiefManagerId, 'remarkChiefManagerId', 'chief');
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        try {
            $workflow->addRemark($this->selectedLetter($workflow), $employee, [
                'manager_id' => $manager?->id,
                'chief_manager_id' => $chiefManager?->id,
                'remark_content' => $hasManagerRemark ? $remarkContent : '',
                'secretary_remark_content' => $hasSecretaryRemark ? $secretaryRemarkContent : null,
            ]);
        } catch (\RuntimeException $e) {
            $this->failWith($e);

            return;
        }

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
        $tier = $workflow->reviewerTier($employee);

        if ($tier !== null) {
            $this->editingRemarkManagerId = $tier === 'manager' ? $employee->id : '';
            $this->editingRemarkChiefManagerId = $tier === 'chief' ? $employee->id : '';
            $this->editingSecretaryRemarkContent = '';
        }

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
            $manager = $tier === 'manager'
                ? $employee
                : $this->resolveRegionalRemarkReviewer($workflow, $employee, $this->editingRemarkManagerId, 'editingRemarkManagerId', 'manager');
        }

        if ($hasChiefManager) {
            $chiefManager = $tier === 'chief'
                ? $employee
                : $this->resolveRegionalRemarkReviewer($workflow, $employee, $this->editingRemarkChiefManagerId, 'editingRemarkChiefManagerId', 'chief');
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $remark = LetterRemark::query()->findOrFail($this->editingRemarkId);
        try {
            $workflow->updateRemark($remark, $employee, [
                'manager_id' => $manager?->id,
                'chief_manager_id' => $chiefManager?->id,
                'remark_content' => $hasManagerRemark ? $remarkContent : '',
                'secretary_remark_content' => $hasSecretaryRemark ? $secretaryRemarkContent : null,
            ]);
        } catch (\RuntimeException $e) {
            $this->failWith($e);

            return;
        }

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

        try {
            $workflow->updateLetter($letter, $this->requireEmployee(), [
                'subject' => $validated['editSubject'],
                'ref_no' => $validated['editRefNo'],
                'type' => $validated['editType'],
                'memo_sender_id' => $validated['editMemoSenderId'],
                'company_sender' => $validated['editCompanySender'],
                'date_on_letter' => $validated['editDateOnLetter'],
            ]);
        } catch (\RuntimeException $e) {
            $this->failWith($e);

            return;
        }

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
                'senders' => collect(),
                'managerOptions' => [],
                'chiefManagerOptions' => [],
                'canRemark' => false,
                'canForward' => false,
            ]);
        }

        $letters = $this->lettersQuery($workflow, $employee)
            ->with([
                'region',
                'memoSender',
                'creator',
                'statusLogs.secretariat',
                'routingHistories.fromSecretariat',
                'routingHistories.toSecretariat',
            ])
            ->paginate($this->perPage);

        $desk = $workflow->deskState($letters->getCollection(), $employee);
        $pageActionable = $this->actionableIds($desk);
        $bulk = $this->bulkSummary($workflow, $employee);

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
                    'latestDelivery.deliveredTo',
                    'latestDelivery.deliveredBy',
                ])
                ->find($this->selectedLetterId)
            : null;

        $selectedDesk = $selectedLetter
            ? $workflow->deskState(collect([$selectedLetter]), $employee)->get($selectedLetter->id)
            : null;
        $scanService = app(LetterScanService::class);
        $scansEnabled = $scanService->enabled();
        $selectedScans = $selectedLetter && $scansEnabled
            ? $selectedLetter->scans()->active()->with('uploadedBy')->orderBy('created_at')->orderBy('id')->get()
            : collect();
        $tier = $workflow->reviewerTier($employee);
        $showReviewers = $selectedLetter !== null && $this->detailTab === 'remarks';

        return view('livewire.letters.active-letters', [
            'missingEmployee' => false,
            'letters' => $letters,
            'desk' => $desk,
            'outgoing' => $workflow->outgoingAwaiting($letters->getCollection(), $employee),
            'workflow' => $workflow,
            'allOnPageSelected' => $pageActionable !== [] && array_diff($pageActionable, $this->selectedIds()) === [],
            'hasActionableRows' => $pageActionable !== [],
            'bulk' => $bulk,
            'maxBatchSize' => max(1, (int) config('gwl.letters_max_batch_size', 50)),
            'bulkPicker' => $this->bulkDispatchOpen
                ? $workflow->recipientPicker($employee, $bulk['dispatchLetters'], $this->bulkSearch, $this->bulkScope)
                : null,
            'selectedLetter' => $selectedLetter,
            'selectedDesk' => $selectedDesk,
            // The pickers and reviewer lists are only built when the panel that shows them is open.
            'picker' => $selectedLetter && $selectedDesk['canDispatch'] && $this->detailTab === 'dispatch' && $this->canForward()
                ? $workflow->recipientPicker($employee, collect([$selectedLetter]), $this->recipientSearch, $this->dispatchScope)
                : null,
            'reviewerTier' => $tier,
            'scansEnabled' => $scansEnabled,
            'previewScansBeforeConfirm' => $scanService->previewBeforeConfirm(),
            'selectedScans' => $selectedScans,
            'voidableScanIds' => $selectedScans->filter(fn (LetterScan $scan) => $scanService->canVoid($scan->setRelation('letter', $selectedLetter), $employee))->pluck('id')->all(),
            'canAddScans' => $selectedLetter && $scansEnabled && $selectedDesk['holdsLetter'],
            'scanKinds' => LetterScan::KINDS,
            'managerOptions' => $showReviewers && $tier === null
                ? $this->employeeOptions($workflow->regionalManagersQuery($employee)->with(['department', 'region'])->get())
                : [],
            'chiefManagerOptions' => $showReviewers && $tier === null
                ? $this->employeeOptions($workflow->regionalChiefManagersQuery($employee)->with(['department', 'region'])->get())
                : [],
            'deliverEmployees' => $selectedLetter && $selectedDesk['holdsLetter'] && $this->detailTab === 'deliver' && $this->deliverMode === 'employee'
                ? Employee::query()
                    ->active()
                    ->visibleInErp()
                    ->with(['department', 'district'])
                    ->when(filled($this->deliverEmployeeSearch), fn ($query) => $query->where(fn ($match) => $match
                        ->where('full_name', 'like', '%'.trim($this->deliverEmployeeSearch).'%')
                        ->orWhere('staff_id', 'like', '%'.trim($this->deliverEmployeeSearch).'%')))
                    ->orderBy('full_name')
                    ->limit(30)
                    ->get()
                : collect(),
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
        ]);
    }

    /** The list behind the page: what the actor can see, narrowed by tab, type, search and the quick filter. */
    protected function lettersQuery(LetterWorkflowService $workflow, Employee $employee): Builder
    {
        return $workflow->visibleLettersQuery($employee)
            ->when(app(LetterScanService::class)->enabled(), fn ($query) => $query->withCount(['scans as active_scans_count' => fn ($scans) => $scans->whereNull('voided_at')]))
            ->when($this->tab === 'active', fn ($query) => $query->whereNull('closed_at'))
            ->when($this->tab === 'closed', fn ($query) => $query->whereNotNull('closed_at'))
            ->when($this->quickFilter === 'awaiting', fn ($query) => $workflow->whereAwaitingConfirmation($query, $employee))
            ->when($this->quickFilter === 'ready', fn ($query) => $workflow->whereReadyToDispatch($query, $employee))
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
            ->latest();
    }

    /** The ticked ids as unique ints, capped so a tampered payload cannot make a huge IN (...). */
    protected function selectedIds(): array
    {
        return collect($this->selected)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->take(200)
            ->values()
            ->all();
    }

    /**
     * The ticked letters the actor can actually see (anything else is dropped silently) with their desk state.
     *
     * @return array{0: Collection<int, MailLetter>, 1: Collection}
     */
    protected function selectionState(LetterWorkflowService $workflow, Employee $employee): array
    {
        $ids = $this->selectedIds();

        $letters = $ids === []
            ? new \Illuminate\Database\Eloquent\Collection
            : $workflow->visibleLettersQuery($employee)->whereIn('id', $ids)->get();

        return [$letters, $workflow->deskState($letters, $employee)];
    }

    /** Rows the actor can tick: awaiting their confirmation, or (with letters.forward) ready to dispatch. */
    protected function actionableIds(Collection $desk): array
    {
        $canForward = $this->canForward();

        return $desk
            ->filter(fn (array $state) => $state['pendingRoute'] !== null || ($canForward && $state['canDispatch']))
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** After a single-letter action from the drawer: untick rows that action made non-actionable (e.g. just dispatched). */
    protected function pruneStaleSelection(LetterWorkflowService $workflow): void
    {
        if ($this->selectedIds() === []) {
            return;
        }

        $this->pruneSelection(...$this->selectionState($workflow, $this->requireEmployee()));
    }

    /** After a refused or stale bulk action: keep only the ticks that are still actionable. */
    protected function pruneSelection(Collection $letters, Collection $desk): void
    {
        $actionable = $this->actionableIds($desk);

        $this->selected = $letters->pluck('id')->map(fn ($id) => (int) $id)->intersect($actionable)->values()->all();
    }

    /**
     * What the sticky bar and the dispatch drawer show for the current ticks. Empty (no queries) when nothing is ticked.
     *
     * @return array{count: int, confirmable: int, dispatchable: int, dispatchLetters: Collection}
     */
    protected function bulkSummary(LetterWorkflowService $workflow, Employee $employee): array
    {
        $summary = ['count' => 0, 'confirmable' => 0, 'dispatchable' => 0, 'dispatchLetters' => collect()];

        if ($this->selectedIds() === []) {
            return $summary;
        }

        [$letters, $desk] = $this->selectionState($workflow, $employee);
        $canForward = $this->canForward();

        return [
            'count' => $letters->count(),
            'confirmable' => $letters->filter(fn (MailLetter $letter) => $desk[$letter->id]['pendingRoute'] !== null)->count(),
            'dispatchable' => $letters->filter(fn (MailLetter $letter) => $canForward && $desk[$letter->id]['canDispatch'])->count(),
            // The drawer lists what it froze on opening, not a fresh recount (see $bulkLetterIds).
            'dispatchLetters' => $this->bulkDispatchOpen
                ? $letters->whereIn('id', $this->bulkLetterIds)->sortBy(fn (MailLetter $letter) => array_search($letter->id, $this->bulkLetterIds, true))->values()
                : collect(),
        ];
    }

    /** A rule the service enforces (stale tab, double click, another device): show it instead of a 500. */
    protected function failWith(\RuntimeException $e): void
    {
        $this->flashMessage = '';
        $this->dispatch('toast', type: 'error', message: $e->getMessage());
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

    protected function resetDeliverForm(): void
    {
        $this->deliverMode = 'employee';
        $this->deliverEmployeeId = '';
        $this->deliverEmployeeSearch = '';
        $this->deliverName = '';
        $this->deliverAt = '';
        $this->deliverNote = '';
    }

    protected function resetDetailInputs(): void
    {
        $this->resetRemarkForm();
        $this->resetEditingRemarkForm();
        $this->resetDeliverForm();
        $this->dispatchToId = '';
        $this->recipientSearch = '';
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
                ? 'Select a chief manager from your office.'
                : 'Select a manager from your office.');
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
