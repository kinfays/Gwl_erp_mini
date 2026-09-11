<?php

namespace App\Livewire\CreditUnion;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\CreditUnionMember;
use App\Models\Employee;
use App\Models\Permission;
use App\Services\CreditUnion\MemberRegistrationService;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;
use Livewire\WithPagination;

class MembersList extends Component
{
    use EnforcesModuleAccess;
    use WithPagination;

    public string $search = '';

    public string $typeFilter = '';

    public string $statusFilter = '';

    public bool $showForm = false;

    public string $formMode = 'staff';

    public ?int $editingId = null;

    public string $employeeSearch = '';

    public array $form = [
        'employee_id' => '',
        'full_name' => '',
        'phone' => '',
        'address' => '',
        'legacy_account_number' => '',
        'registered_at' => '',
        'membership_form_fee_amount' => '',
        'membership_form_fee_paid_at' => '',
        'initial_share_amount' => '',
        'default_monthly_savings_amount' => '',
        'default_monthly_shares_amount' => '',
    ];

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_CREDIT_UNION);
        $this->guardManageMembers();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function openStaffForm(): void
    {
        $this->guardManageMembers();
        $this->resetForm();
        $this->formMode = 'staff';
        $this->showForm = true;
    }

    public function openAssociateForm(): void
    {
        $this->guardManageMembers();
        $this->resetForm();
        $this->formMode = 'associate';
        $this->showForm = true;
    }

    public function openEdit(int $memberId): void
    {
        $this->guardManageMembers();

        $member = CreditUnionMember::query()->findOrFail($memberId);

        $this->resetForm();
        $this->formMode = 'edit';
        $this->editingId = $member->id;
        $this->showForm = true;
        $this->form = [
            ...$this->form,
            'full_name' => (string) $member->full_name,
            'phone' => (string) $member->phone,
            'address' => (string) $member->address,
            'legacy_account_number' => (string) $member->legacy_account_number,
            'default_monthly_savings_amount' => (string) $member->default_monthly_savings_amount,
            'default_monthly_shares_amount' => (string) $member->default_monthly_shares_amount,
        ];
    }

    public function closeForm(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    public function save(MemberRegistrationService $registration): void
    {
        $this->guardManageMembers();

        $rules = [
            'form.phone' => ['nullable', 'string', 'max:30'],
            'form.address' => ['nullable', 'string', 'max:255'],
            'form.legacy_account_number' => ['nullable', 'string', 'max:60'],
            'form.default_monthly_savings_amount' => ['nullable', 'numeric', 'min:0'],
            'form.default_monthly_shares_amount' => ['nullable', 'numeric', 'min:0'],
        ];

        if ($this->formMode === 'staff') {
            $rules['form.employee_id'] = ['required', 'integer', 'exists:employees,id'];
        }

        if ($this->formMode !== 'staff') {
            $rules['form.full_name'] = ['required', 'string', 'max:255'];
        }

        if ($this->formMode !== 'edit') {
            $rules['form.registered_at'] = ['nullable', 'date'];
            $rules['form.membership_form_fee_amount'] = ['nullable', 'numeric', 'min:0'];
            $rules['form.membership_form_fee_paid_at'] = ['nullable', 'date'];
            $rules['form.initial_share_amount'] = ['nullable', 'numeric', 'min:0'];
        }

        $this->validate($rules);

        $payload = $this->payload();

        if ($this->formMode === 'edit') {
            $member = CreditUnionMember::query()->findOrFail($this->editingId);
            $registration->updateMember($member, $payload);
            $this->closeForm();
            $this->dispatch('toast', type: 'success', message: 'Member updated.');

            return;
        }

        if ($this->formMode === 'staff') {
            // Resolved off the full directory so an already-registered employee surfaces
            // the service's "already a member" validation error rather than a 404.
            $employee = Employee::query()->findOrFail((int) $this->form['employee_id']);
            $member = $registration->registerStaffMember($employee, $payload, auth()->id());
        } else {
            $member = $registration->registerAssociateMember($payload, auth()->id());
        }

        $this->closeForm();
        $this->dispatch('toast', type: 'success', message: 'Member '.$member->member_number.' registered.');
    }

    protected function payload(): array
    {
        return [
            'full_name' => trim((string) $this->form['full_name']) ?: null,
            'phone' => $this->nullableString($this->form['phone']),
            'address' => $this->nullableString($this->form['address']),
            'legacy_account_number' => $this->nullableString($this->form['legacy_account_number']),
            'registered_at' => $this->nullableString($this->form['registered_at']) ?? today()->toDateString(),
            'membership_form_fee_amount' => $this->nullableString($this->form['membership_form_fee_amount'])
                ?? config('gwl.credit_union_membership_form_fee'),
            'membership_form_fee_paid_at' => $this->nullableString($this->form['membership_form_fee_paid_at']),
            'initial_share_amount' => $this->nullableString($this->form['initial_share_amount'])
                ?? config('gwl.credit_union_initial_share_amount'),
            'default_monthly_savings_amount' => $this->nullableString($this->form['default_monthly_savings_amount']),
            'default_monthly_shares_amount' => $this->nullableString($this->form['default_monthly_shares_amount']),
        ];
    }

    protected function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    protected function resetForm(): void
    {
        $this->resetValidation();
        $this->editingId = null;
        $this->employeeSearch = '';
        $this->form = [
            'employee_id' => '',
            'full_name' => '',
            'phone' => '',
            'address' => '',
            'legacy_account_number' => '',
            'registered_at' => today()->toDateString(),
            'membership_form_fee_amount' => (string) config('gwl.credit_union_membership_form_fee'),
            'membership_form_fee_paid_at' => today()->toDateString(),
            'initial_share_amount' => (string) config('gwl.credit_union_initial_share_amount'),
            'default_monthly_savings_amount' => '',
            'default_monthly_shares_amount' => '',
        ];
    }

    protected function guardManageMembers(): void
    {
        $user = auth()->user();

        if (! $user || (! $user->hasRoles('super_admin') && ! $user->hasPermission('credit_union.manage_members'))) {
            abort(403);
        }
    }

    /**
     * The union is national, not region-scoped, so an officer registers from the whole
     * active directory rather than through Services/Staff/EmployeeDirectory's HR-role
     * scoping (which resolves to an empty set for the credit union roles anyway).
     * Employees who already hold a membership record are filtered out.
     */
    protected function employeeQuery(): Builder
    {
        return Employee::query()
            ->visibleInErp()
            ->where('is_active', true)
            ->whereNotIn('id', CreditUnionMember::query()->whereNotNull('employee_id')->pluck('employee_id'));
    }

    protected function memberQuery(): Builder
    {
        return CreditUnionMember::query()
            ->with(['employee'])
            ->when($this->search !== '', function (Builder $query) {
                $term = '%'.$this->search.'%';
                $query->where(function (Builder $inner) use ($term) {
                    $inner->where('full_name', 'like', $term)
                        ->orWhere('member_number', 'like', $term)
                        ->orWhere('staff_id', 'like', $term);
                });
            })
            ->when($this->typeFilter !== '', fn (Builder $query) => $query->where('member_type', $this->typeFilter))
            ->when($this->statusFilter !== '', fn (Builder $query) => $query->where('status', $this->statusFilter));
    }

    public function render()
    {
        $employees = collect();

        if ($this->showForm && $this->formMode === 'staff') {
            $employees = $this->employeeQuery()
                ->when($this->employeeSearch !== '', function (Builder $query) {
                    $term = '%'.$this->employeeSearch.'%';
                    $query->where(fn (Builder $inner) => $inner->where('full_name', 'like', $term)->orWhere('staff_id', 'like', $term));
                })
                ->orderBy('full_name')
                ->limit(25)
                ->get(['id', 'staff_id', 'full_name']);
        }

        return view('livewire.credit-union.members-list', [
            'members' => $this->memberQuery()->orderByDesc('id')->paginate(15),
            'employees' => $employees,
            'memberTypes' => CreditUnionMember::TYPES,
            'statuses' => CreditUnionMember::STATUSES,
            'pendingCount' => CreditUnionMember::query()->pending()->count(),
            'totals' => [
                'all' => CreditUnionMember::query()->count(),
                'staff' => CreditUnionMember::query()->ofType(CreditUnionMember::TYPE_STAFF)->active()->count(),
                'associate' => CreditUnionMember::query()->ofType(CreditUnionMember::TYPE_ASSOCIATE)->active()->count(),
            ],
        ]);
    }
}
