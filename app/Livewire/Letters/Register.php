<?php

namespace App\Livewire\Letters;

use App\Exceptions\Letters\RegisterTooLargeException;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\Employee;
use App\Services\Letters\LetterRegisterService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

/** "My register": the holder's letters for a date range and scope, previewed 25 at a time, with Excel and PDF export. */
class Register extends Component
{
    use EnforcesModuleAccess;
    use WithPagination;

    public const PER_PAGE = 25;

    /** Y-m-d, on date received. Defaults to the current month. */
    public string $from = '';

    public string $to = '';

    public string $scope = 'all';

    public function mount(): void
    {
        $this->enforceLivewireModule('letters');

        $user = auth()->user();
        abort_unless($user && ($user->hasRoles('super_admin') || $user->hasPermission('letters.export')), 403);

        $this->from = $this->validDate(request()->query('from')) ?? today()->startOfMonth()->toDateString();
        $this->to = $this->validDate(request()->query('to')) ?? today()->endOfMonth()->toDateString();
        $this->scope = app(LetterRegisterService::class)->normalizeScope(request()->query('scope'));
    }

    public function updating($name): void
    {
        if (in_array($name, ['from', 'to', 'scope'], true)) {
            $this->resetPage();
        }
    }

    public function setScope(string $scope): void
    {
        $this->scope = app(LetterRegisterService::class)->normalizeScope($scope);
        $this->resetPage();
    }

    public function render(LetterRegisterService $register)
    {
        $employee = $this->employee();

        if (! $employee) {
            return view('livewire.letters.register', ['missingEmployee' => true, 'rows' => null, 'error' => null, 'total' => 0, 'query' => [], 'register' => $register]);
        }

        $from = $this->validDate($this->from);
        $to = $this->validDate($this->to);
        $query = ['from' => $from ?? '', 'to' => $to ?? '', 'scope' => $this->scope];
        $error = null;
        $paginator = null;
        $total = 0;

        if ($from === null || $to === null) {
            $error = 'Choose a valid start and end date.';
        } else {
            [$start, $end] = $from <= $to ? [$from, $to] : [$to, $from];
            $query['from'] = $start;
            $query['to'] = $end;

            try {
                $rows = $register->rows($employee, Carbon::parse($start), Carbon::parse($end), $this->scope);
                $total = $rows->count();
                $page = $this->getPage();

                $paginator = new LengthAwarePaginator(
                    $rows->forPage($page, self::PER_PAGE)->map(fn (array $row) => $register->cells($row))->values(),
                    $total,
                    self::PER_PAGE,
                    $page,
                    ['path' => request()->url(), 'pageName' => 'page']
                );
            } catch (RegisterTooLargeException $e) {
                $error = $e->getMessage();
            }
        }

        return view('livewire.letters.register', [
            'missingEmployee' => false,
            'rows' => $paginator,
            'error' => $error ?? session('error'),
            'total' => $total,
            'query' => $query,
            'register' => $register,
        ]);
    }

    protected function validDate(mixed $date): ?string
    {
        if (! is_string($date) || $date === '') {
            return null;
        }

        try {
            return Carbon::parse($date)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    protected function employee(): ?Employee
    {
        $user = auth()->user();

        return $user?->employee ?? $user?->employeeByStaffId;
    }
}
