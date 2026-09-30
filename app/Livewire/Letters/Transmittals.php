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
 * under "Individual letters") as a pre-ticked checklist. Sent: transmittals I created and how far each has got.
 */
class Transmittals extends Component
{
    use EnforcesModuleAccess;
    use WithPagination;

    public string $tab = 'incoming';

    /** The transmittal a notification / the "created" banner pointed at; its card is highlighted. */
    public ?int $focusBatch = null;

    /**
     * Pending hop ids the user has UNticked. Lines are pre-ticked, so a hop that arrives later is ticked too.
     *
     * @var array<int, int|string>
     */
    public array $unticked = [];

    public function mount(): void
    {
        $this->enforceLivewireModule('letters');

        $this->tab = request()->query('tab') === 'sent' ? 'sent' : 'incoming';
        $this->focusBatch = request()->integer('batch') ?: null;
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab === 'sent' ? 'sent' : 'incoming';
        $this->focusBatch = null;
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

    /**
     * Confirm the hardcopies of one group of my pending hops: a batch id, or 'individual' for the un-batched ones.
     * $onlyTicked leaves the unticked lines pending. Only my own hops are ever touched (the service re-checks).
     */
    public function confirmGroup(LetterWorkflowService $workflow, string $group, bool $onlyTicked = false): void
    {
        $employee = $this->requireEmployee();
        $batch = $group === 'individual' ? null : LetterDispatchBatch::query()->where('to_secretariat_id', $employee->id)->find((int) $group);

        if ($group !== 'individual' && ! $batch) {
            $this->dispatch('toast', type: 'error', message: 'That transmittal is not addressed to you.');

            return;
        }

        $hops = $this->pendingHops($employee)
            ->when($batch, fn ($query) => $query->where('batch_id', $batch->id), fn ($query) => $query->whereNull('batch_id'))
            ->when($onlyTicked, fn ($query) => $query->whereNotIn('id', collect($this->unticked)->map(fn ($id) => (int) $id)->all()))
            ->get();

        if ($hops->isEmpty()) {
            $this->dispatch('toast', type: 'error', message: $onlyTicked ? 'Tick at least one letter to confirm.' : 'Nothing in this group is waiting for your confirmation.');

            return;
        }

        try {
            $confirmed = $workflow->confirmHardcopies($employee, $hops->pluck('letter_id')->all(), $batch);
        } catch (\RuntimeException $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return;
        }

        $this->unticked = collect($this->unticked)->map(fn ($id) => (int) $id)->diff($hops->pluck('id'))->values()->all();
        $this->dispatch('toast', type: 'success', message: 'Confirmed hardcopy receipt for '.$confirmed.' '.Str::plural('letter', $confirmed).'.');
    }

    public function render()
    {
        $employee = $this->employee();

        if (! $employee) {
            return view('livewire.letters.transmittals', ['missingEmployee' => true, 'groups' => collect(), 'sent' => null, 'pendingTotal' => 0]);
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

        $sent = $this->tab === 'sent'
            ? LetterDispatchBatch::query()
                ->where('from_secretariat_id', $employee->id)
                ->with(['toSecretariat', 'routingHistories' => fn ($hops) => $hops->orderBy('id'), 'routingHistories.letter'])
                ->orderByDesc('dispatched_at')
                ->orderByDesc('id')
                ->paginate(10)
            : null;

        return view('livewire.letters.transmittals', [
            'missingEmployee' => false,
            'groups' => $groups,
            'sent' => $sent,
            'pendingTotal' => $pending->count(),
        ]);
    }

    protected function pendingHops(Employee $employee)
    {
        return RoutingHistory::query()
            ->where('to_secretariat_id', $employee->id)
            ->where('received_confirm', false);
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
