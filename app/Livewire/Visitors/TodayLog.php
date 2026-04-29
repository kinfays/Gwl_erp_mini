<?php

namespace App\Livewire\Visitors;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\Visitor;
use Livewire\Component;
use Livewire\WithPagination;

class TodayLog extends Component
{
    use EnforcesModuleAccess;
    use WithPagination;

    public string $search = '';
    public string $status = '';
    public ?int $signatureVisitorId = null;
    public int $perPage = 15;

    public function mount(): void
    {
        $this->enforceLivewireModule('visitors');
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function checkOut(int $visitorId): void
    {
        $visitor = Visitor::query()
            ->today()
            ->inside()
            ->findOrFail($visitorId);

        $visitor->update([
            'check_out_at' => now(),
            'checked_out_by' => 'receptionist',
        ]);
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
        $base = Visitor::query()->today();

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

        return view('livewire.visitors.today-log', [
            'stats' => [
                'total' => (clone $base)->count(),
                'inside' => (clone $base)->inside()->count(),
                'out' => (clone $base)->checkedOut()->count(),
                'autoTime' => config('gwcl.visitors_auto_checkout_time', '18:00'),
            ],
            'visitors' => $visitors,
            'signatureVisitor' => $this->signatureVisitorId ? Visitor::find($this->signatureVisitorId) : null,
        ]);
    }
}
