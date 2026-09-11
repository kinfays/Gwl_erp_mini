<?php

namespace App\Livewire\CreditUnion;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\CreditUnionMember;
use App\Models\Permission;
use App\Services\CreditUnion\MemberRegistrationService;
use Livewire\Component;

class ApplyMembership extends Component
{
    use EnforcesModuleAccess;

    public array $form = [
        'phone' => '',
        'address' => '',
    ];

    public function mount(): void
    {
        $this->enforceLivewireModule(Permission::MODULE_CREDIT_UNION);
        $this->guardPermission();
    }

    public function submit(MemberRegistrationService $registration): void
    {
        $this->guardPermission();

        $validated = $this->validate([
            'form.phone' => ['nullable', 'string', 'max:30'],
            'form.address' => ['nullable', 'string', 'max:255'],
        ]);

        $registration->submitSelfApplication(auth()->user(), $validated['form']);

        $this->form = ['phone' => '', 'address' => ''];

        $this->dispatch('toast', type: 'success', message: 'Application submitted. You will be notified once the committee decides.');
    }

    protected function guardPermission(): void
    {
        $user = auth()->user();

        if (! $user || (! $user->hasRoles('super_admin') && ! $user->hasPermission('credit_union.apply_membership'))) {
            abort(403);
        }
    }

    protected function employee()
    {
        $user = auth()->user();

        return $user?->employee ?? $user?->employeeByStaffId;
    }

    protected function existingMembership(): ?CreditUnionMember
    {
        $employee = $this->employee();

        if (! $employee) {
            return null;
        }

        return CreditUnionMember::query()
            ->where('employee_id', $employee->id)
            ->orWhere(function ($query) use ($employee) {
                $query->whereNotNull('staff_id')->where('staff_id', $employee->staff_id);
            })
            ->first();
    }

    public function render()
    {
        return view('livewire.credit-union.apply-membership', [
            'employee' => $this->employee(),
            'membership' => $this->existingMembership(),
            'formFee' => (float) config('gwl.credit_union_membership_form_fee'),
            'initialShare' => (float) config('gwl.credit_union_initial_share_amount'),
        ]);
    }
}
