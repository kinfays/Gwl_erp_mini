<?php

namespace App\Livewire\Visitors;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\Visitor;
use Livewire\Component;
use Livewire\WithPagination;

class HistoryLog extends Component
{
    use EnforcesModuleAccess;
    use WithPagination;

    public string $date = '';
    public string $search = '';
    public string $status = '';
    public ?int $signatureVisitorId = null;
    public int $perPage = 15;

    public function mount(): void
    {
        $this->enforceLivewireModule('visitors');
        $this->date = today()->toDateString();
    }

    public function updating($name): void
    {
        if (in_array($name, ['date', 'search', 'status'], true)) {
            $this->resetPage();
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
        $base = Visitor::query()->whereDate('check_in_at', $this->date ?: today());

        $visitors = (clone $base)
            ->with(['staff.department'])
            ->when($this->search, function ($query) {
                $query->where(function ($searchQuery) {
                    $searchQuery
                        ->where('visitor_name', 'like', '%' . $this->search . '%')
                        ->orWhereHas('staff', fn ($staffQuery) => $staffQuery->where('full_name', 'like', '%' . $this->search . '%'));
                });
            })
            ->when($this->status === 'inside', fn ($query) => $query->inside())
            ->when($this->status === 'out', fn ($query) => $query->checkedOut())
            ->latest('check_in_at')
            ->paginate($this->perPage);

        return view('livewire.visitors.history-log', [
            'visitors' => $visitors,
            'signatureVisitor' => $this->signatureVisitorId ? Visitor::find($this->signatureVisitorId) : null,
            'exportDate' => $this->date ?: today()->toDateString(),
        ]);
    }
}
