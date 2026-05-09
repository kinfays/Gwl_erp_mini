<?php

namespace App\Livewire\Visitors;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\Visitor;
use Illuminate\Support\Carbon;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

class HistoryLog extends Component
{
    use EnforcesModuleAccess;
    use WithPagination;

    public string $startDate = '';

    public string $endDate = '';

    public string $search = '';

    public string $status = '';

    public ?int $signatureVisitorId = null;

    public int $perPage = 15;

    public function mount(): void
    {
        $this->enforceLivewireModule('visitors');
        $this->startDate = today()->toDateString();
        $this->endDate = today()->toDateString();
    }

    public function updating($name): void
    {
        if (in_array($name, ['startDate', 'endDate', 'search', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function updatedStartDate(): void
    {
        if ($this->startDate && $this->endDate && $this->startDate > $this->endDate) {
            $this->endDate = $this->startDate;
        }
    }

    public function updatedEndDate(): void
    {
        if ($this->startDate && $this->endDate && $this->endDate < $this->startDate) {
            $this->startDate = $this->endDate;
        }
    }

    public function showSignature(int $visitorId): void
    {
        $this->signatureVisitorId = $visitorId;
    }

    public function closeSignature(): void
    {
        $this->signatureVisitorId = null;
    }

    public function render()
    {
        [$start, $end] = $this->selectedRange();

        $base = Visitor::query()->whereBetween('check_in_at', [
            $start->copy()->startOfDay(),
            $end->copy()->endOfDay(),
        ]);

        $visitors = (clone $base)
            ->with(['staff.department'])
            ->when($this->search, function ($query) {
                $query->where(function ($searchQuery) {
                    $searchQuery
                        ->where('visitor_name', 'like', '%'.$this->search.'%')
                        ->orWhereHas('staff', fn ($staffQuery) => $staffQuery->where('full_name', 'like', '%'.$this->search.'%'));
                });
            })
            ->when($this->status === 'inside', fn ($query) => $query->inside())
            ->when($this->status === 'out', fn ($query) => $query->checkedOut())
            ->latest('check_in_at')
            ->paginate($this->perPage);

        return view('livewire.visitors.history-log', [
            'visitors' => $visitors,
            'signatureVisitor' => $this->signatureVisitorId ? Visitor::find($this->signatureVisitorId) : null,
            'exportStartDate' => $start->toDateString(),
            'exportEndDate' => $end->toDateString(),
        ]);
    }

    protected function selectedRange(): array
    {
        $start = $this->parseDate($this->startDate) ?? today();
        $end = $this->parseDate($this->endDate) ?? $start->copy();

        if ($start->gt($end)) {
            [$start, $end] = [$end, $start];
        }

        return [$start, $end];
    }

    protected function parseDate(string $date): ?Carbon
    {
        if ($date === '') {
            return null;
        }

        try {
            return Carbon::parse($date)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
