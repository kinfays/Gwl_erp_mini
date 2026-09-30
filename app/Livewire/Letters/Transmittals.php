<?php

namespace App\Livewire\Letters;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\Employee;
use App\Models\LetterDispatchBatch;
use App\Models\RoutingHistory;
use App\Services\Letters\LetterWorkflowService;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Incoming: hardcopies handed to me that I have not confirmed yet, grouped by transmittal (single dispatches sit
 * under "Individual letters") as a pre-ticked checklist, with Confirm and Reject. Sent: transmittals I created and how
 * far each has got, plus my single dispatches still waiting, with Recall, Remind and an Overdue filter.
 */
class Transmittals extends Component
{
    use EnforcesModuleAccess;
    use WithPagination;

    public string $tab = 'incoming';

    /** The transmittal a notification / the "created" banner pointed at; its card is highlighted. */
    public ?int $focusBatch = null;

    /** Sent tab: '' (everything) or 'overdue' (waiting at least letters_unconfirmed_alert_days). */
    public string $sentFilter = '';

    /**
     * Pending hop ids the user has UNticked. Lines are pre-ticked, so a hop that arrives later is ticked too.
     *
     * @var array<int, int|string>
     */
    public array $unticked = [];

    /** The Incoming group whose "Reject ticked" panel is open (a batch id or 'individual'), and its reason. */
    public ?string $rejectGroup = null;

    public string $rejectReason = '';

    public function mount(): void
    {
        $this->enforceLivewireModule('letters');

        $this->tab = request()->query('tab') === 'sent' ? 'sent' : 'incoming';
        $this->sentFilter = request()->query('filter') === 'overdue' ? 'overdue' : '';
        $this->focusBatch = request()->integer('batch') ?: null;
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab === 'sent' ? 'sent' : 'incoming';
        $this->focusBatch = null;
        $this->cancelReject();
        $this->resetPage();
    }

    public function setSentFilter(string $filter): void
    {
        $this->sentFilter = $filter === 'overdue' ? 'overdue' : '';
        $this->resetPage();
    }

    public function toggleLine(int $hopId): void
    {
        $unticked = collect($this->unticked)->map(fn ($id) => (int) $id);

        $this->unticked = ($unticked->contains($hopId) ? $unticked->reject(fn (int $id) => $id === $hopId) : $unticked->push($hopId))
            ->unique()
            ->values()
            ->all();
    }

    // ---- Incoming ---------------------------------------------------------------------------------------

    /**
     * Confirm the hardcopies of one group of my pending hops: a batch id, or 'individual' for the un-batched ones.
     * $onlyTicked leaves the unticked lines pending. Only my own hops are ever touched (the service re-checks).
     */
    public function confirmGroup(LetterWorkflowService $workflow, string $group, bool $onlyTicked = false): void
    {
        $employee = $this->requireEmployee();
        $batch = $group === 'individual' ? null : LetterDispatchBatch::query()->where('to_secretariat_id', $employee->id)->find((int) $group);

        if ($group !== 'individual' && ! $batch) {
            $this->toast('error', 'That transmittal is not addressed to you.');

            return;
        }

        $hops = $this->groupHops($employee, $batch, $onlyTicked)->get();

        if ($hops->isEmpty()) {
            $this->toast('error', $onlyTicked ? 'Tick at least one letter to confirm.' : 'Nothing in this group is waiting for your confirmation.');

            return;
        }

        try {
            $confirmed = $workflow->confirmHardcopies($employee, $hops->pluck('letter_id')->all(), $batch);
        } catch (\RuntimeException $e) {
            $this->toast('error', $e->getMessage());

            return;
        }

        $this->forgetUnticked($hops->pluck('id'));
        $this->toast('success', 'Confirmed hardcopy receipt for '.$confirmed.' '.Str::plural('letter', $confirmed).'.');
    }

    public function openReject(string $group): void
    {
        $this->rejectGroup = $group;
        $this->rejectReason = '';
        $this->resetErrorBag();
    }

    public function cancelReject(): void
    {
        $this->rejectGroup = null;
        $this->rejectReason = '';
        $this->resetErrorBag();
    }

    /** Reject the ticked lines of the open group with the reason typed in its panel (mandatory, 5+ characters). */
    public function rejectTicked(LetterWorkflowService $workflow): void
    {
        $employee = $this->requireEmployee();
        $group = $this->rejectGroup;

        abort_if($group === null, 404);

        $this->validate(
            ['rejectReason' => ['required', 'string', 'min:5', 'max:500']],
            [
                'rejectReason.required' => 'Give a reason so the sender knows why.',
                'rejectReason.min' => 'Give a reason of at least 5 characters so the sender knows why.',
            ]
        );

        $batch = $group === 'individual' ? null : LetterDispatchBatch::query()->where('to_secretariat_id', $employee->id)->find((int) $group);

        if ($group !== 'individual' && ! $batch) {
            $this->toast('error', 'That transmittal is not addressed to you.');
            $this->cancelReject();

            return;
        }

        $hops = $this->groupHops($employee, $batch, onlyTicked: true)->get();

        if ($hops->isEmpty()) {
            $this->toast('error', 'Tick at least one letter to reject.');

            return;
        }

        try {
            $rejected = $workflow->rejectLines($employee, $hops->pluck('id')->all(), $this->rejectReason);
        } catch (\RuntimeException $e) {
            $this->toast('error', $e->getMessage());

            return;
        }

        $this->forgetUnticked($hops->pluck('id'));
        $this->cancelReject();
        $this->toast('success', 'Rejected '.$rejected.' '.Str::plural('letter', $rejected).'. The sender has been told why.');
    }

    // ---- Sent -------------------------------------------------------------------------------------------

    public function recallLine(LetterWorkflowService $workflow, int $hopId): void
    {
        abort_if(! $this->canForward(), 403);

        $employee = $this->requireEmployee();
        $hop = RoutingHistory::query()->with('letter')->where('from_secretariat_id', $employee->id)->find($hopId);

        if (! $hop) {
            $this->toast('error', 'That hand-over was not found.');

            return;
        }

        try {
            $workflow->recall($hop, $employee);
        } catch (\RuntimeException $e) {
            $this->toast('error', $e->getMessage());

            return;
        }

        $this->toast('success', ($hop->letter?->sn_number ?? 'Letter').' recalled. It is back on your desk.');
    }

    public function recallBatch(LetterWorkflowService $workflow, int $batchId): void
    {
        abort_if(! $this->canForward(), 403);

        $employee = $this->requireEmployee();
        $batch = LetterDispatchBatch::query()->where('from_secretariat_id', $employee->id)->find($batchId);

        if (! $batch) {
            $this->toast('error', 'That transmittal was not found.');

            return;
        }

        try {
            $recalled = $workflow->recallBatch($batch, $employee);
        } catch (\RuntimeException $e) {
            $this->toast('error', $e->getMessage());

            return;
        }

        $this->toast('success', $recalled.' '.Str::plural('letter', $recalled).' of '.$batch->batch_no.' recalled. '.($recalled === 1 ? 'It is' : 'They are').' back on your desk.');
    }

    public function remindBatch(LetterWorkflowService $workflow, int $batchId): void
    {
        abort_if(! $this->canForward(), 403);

        $employee = $this->requireEmployee();
        $batch = LetterDispatchBatch::query()->where('from_secretariat_id', $employee->id)->find($batchId);

        if (! $batch) {
            $this->toast('error', 'That transmittal was not found.');

            return;
        }

        $this->remind($workflow, $employee, $batch);
    }

    public function remindLine(LetterWorkflowService $workflow, int $hopId): void
    {
        abort_if(! $this->canForward(), 403);

        $employee = $this->requireEmployee();
        $hop = RoutingHistory::query()->where('from_secretariat_id', $employee->id)->find($hopId);

        if (! $hop) {
            $this->toast('error', 'That hand-over was not found.');

            return;
        }

        $this->remind($workflow, $employee, $hop);
    }

    public function render(LetterWorkflowService $workflow)
    {
        $employee = $this->employee();

        if (! $employee) {
            return view('livewire.letters.transmittals', [
                'missingEmployee' => true, 'groups' => collect(), 'sent' => null, 'sentSingles' => collect(),
                'pendingTotal' => 0, 'overdueCount' => 0, 'workflow' => $workflow, 'canForward' => false,
            ]);
        }

        $pending = $this->pendingHops($employee)
            ->with(['letter.memoSender', 'fromSecretariat', 'batch'])
            ->orderBy('id')
            ->get();

        $groups = $pending
            ->groupBy(fn (RoutingHistory $hop) => $hop->batch_id ?? 'individual')
            ->map(fn ($hops, $key) => ['key' => (string) $key, 'batch' => $key === 'individual' ? null : $hops->first()->batch, 'hops' => $hops])
            // Newest transmittal first, individual letters last.
            ->sortBy(fn (array $group) => $group['batch'] ? -$group['batch']->id : PHP_INT_MAX)
            ->values();

        $overdue = $this->sentFilter === 'overdue';
        $days = $workflow->alertDays();
        $sent = null;
        $sentSingles = collect();

        if ($this->tab === 'sent') {
            $sent = LetterDispatchBatch::query()
                ->where('from_secretariat_id', $employee->id)
                ->when($overdue, fn ($query) => $query->whereHas('routingHistories', fn ($hops) => $hops->overdue($days)))
                ->with(['toSecretariat', 'routingHistories' => fn ($hops) => $hops->orderBy('id'), 'routingHistories.letter', 'routingHistories.fromSecretariat', 'routingHistories.toSecretariat'])
                ->orderByDesc('dispatched_at')
                ->orderByDesc('id')
                ->paginate(10);

            // Single dispatches belong here too: the Overdue filter and the dashboard tile count them.
            $sentSingles = RoutingHistory::query()
                ->where('from_secretariat_id', $employee->id)
                ->whereNull('batch_id')
                ->awaiting()
                ->when($overdue, fn ($query) => $query->overdue($days))
                ->with(['letter', 'toSecretariat'])
                ->orderBy('id')
                ->get();
        }

        return view('livewire.letters.transmittals', [
            'missingEmployee' => false,
            'groups' => $groups,
            'sent' => $sent,
            'sentSingles' => $sentSingles,
            'pendingTotal' => $pending->count(),
            'overdueCount' => $workflow->overdueSentCount($employee),
            'workflow' => $workflow,
            'canForward' => $this->canForward(),
        ]);
    }

    protected function remind(LetterWorkflowService $workflow, Employee $employee, RoutingHistory|LetterDispatchBatch $target): void
    {
        try {
            $reminded = $workflow->remind($employee, $target);
        } catch (\RuntimeException $e) {
            $this->toast('error', $e->getMessage());

            return;
        }

        $this->toast('success', 'Reminder sent for '.$reminded.' '.Str::plural('letter', $reminded).'.');
    }

    /** My awaiting hops in one Incoming group, optionally only the ticked ones. */
    protected function groupHops(Employee $employee, ?LetterDispatchBatch $batch, bool $onlyTicked)
    {
        return $this->pendingHops($employee)
            ->when($batch, fn ($query) => $query->where('batch_id', $batch->id), fn ($query) => $query->whereNull('batch_id'))
            ->when($onlyTicked, fn ($query) => $query->whereNotIn('id', collect($this->unticked)->map(fn ($id) => (int) $id)->all()));
    }

    protected function forgetUnticked($hopIds): void
    {
        $this->unticked = collect($this->unticked)->map(fn ($id) => (int) $id)->diff($hopIds)->values()->all();
    }

    protected function pendingHops(Employee $employee)
    {
        return RoutingHistory::query()
            ->where('to_secretariat_id', $employee->id)
            ->awaiting();
    }

    protected function toast(string $type, string $message): void
    {
        $this->dispatch('toast', type: $type, message: $message);
    }

    protected function canForward(): bool
    {
        $user = auth()->user();

        return $user && ($user->hasRoles('super_admin') || $user->hasPermission('letters.forward'));
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
}
