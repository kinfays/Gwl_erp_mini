<?php

namespace App\Livewire\Visitors;

use App\Models\Employee;
use App\Models\Visitor;
use App\Services\Visitors\VisitorService;
use Livewire\Component;

class Kiosk extends Component
{
    public int $step = 1;

    public string $visitor_name = '';

    public string $phone = '';

    public int|string $staff_id = '';

    public string $purpose = '';

    public string $signature = '';

    public ?string $checkoutCode = null;

    public bool $duplicateWarning = false;

    public bool $success = false;

    public string $successName = '';

    public string $selfCheckoutCode = '';

    public ?int $selfCheckoutVisitorId = null;

    public string $selfCheckoutSignature = '';

    public string $selfCheckoutMessage = '';

    public function updatedVisitorName(): void
    {
        $this->checkDuplicate();
    }

    public function updatedPhone(): void
    {
        $this->checkDuplicate();
    }

    public function checkDuplicate(): void
    {
        $phone = trim($this->phone);

        $this->duplicateWarning = $phone !== ''
            && Visitor::query()
                ->today()
                ->inside()
                ->where('phone', $phone)
                ->exists();
    }

    public function next(): void
    {
        if ($this->step === 1) {
            $this->validate([
                'visitor_name' => ['required', 'string', 'max:255'],
                'phone' => ['required', 'digits:10'],
            ]);

            $this->checkDuplicate();
        }

        if ($this->step === 2) {
            $this->validate([
                'staff_id' => ['required', 'exists:employees,id'],
            ]);
        }

        if ($this->step < 4) {
            $this->step++;
        }
    }

    public function back(): void
    {
        if ($this->step > 1) {
            $this->step--;
        }
    }

    public function submit()
    {
        $validated = $this->validate([
            'visitor_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'digits:10'],
            'staff_id' => ['required', 'exists:employees,id'],
            'purpose' => ['nullable', 'string', 'max:1000'],
            'signature' => ['required', 'string'],
        ]);

        $visitor = app(VisitorService::class)->checkIn($validated);

        $this->checkoutCode = $visitor->checkout_code;
        $this->successName = $visitor->visitor_name;
        $this->success = true;
        $this->dispatch('toast', type: 'success', message: 'Visit recorded successfully.');
    }

    public function resetKiosk(): void
    {
        $this->reset([
            'step',
            'visitor_name',
            'phone',
            'staff_id',
            'purpose',
            'signature',
            'checkoutCode',
            'duplicateWarning',
            'success',
            'successName',
        ]);

        $this->step = 1;
    }

    public function findSelfCheckout(): void
    {
        $code = trim($this->selfCheckoutCode);
        $this->selfCheckoutCode = $code;
        $this->selfCheckoutVisitorId = null;
        $this->selfCheckoutSignature = '';

        if ($code === '') {
            $this->selfCheckoutMessage = 'Enter your checkout code to find your visit.';

            return;
        }

        $visitor = Visitor::query()
            ->today()
            ->inside()
            ->where('checkout_code', $code)
            ->latest()
            ->first();

        if (! $visitor) {
            $this->selfCheckoutMessage = 'No active visit was found for that code.';
            $this->dispatch('toast', type: 'error', message: $this->selfCheckoutMessage);

            return;
        }

        $this->selfCheckoutVisitorId = $visitor->id;
        $this->selfCheckoutMessage = '';
    }

    public function cancelSelfCheckout(): void
    {
        $this->selfCheckoutCode = '';
        $this->selfCheckoutVisitorId = null;
        $this->selfCheckoutSignature = '';
        $this->selfCheckoutMessage = '';
        $this->dispatch('kiosk-clear-signature', property: 'selfCheckoutSignature');
    }

    public function confirmSelfCheckout(): void
    {
        if (! $this->selfCheckoutVisitorId) {
            $this->selfCheckoutMessage = 'Enter your checkout code to find your visit.';

            return;
        }

        $visitor = Visitor::query()
            ->today()
            ->inside()
            ->findOrFail($this->selfCheckoutVisitorId);

        app(VisitorService::class)->checkOut($visitor, VisitorService::CHECKOUT_SELF, $this->selfCheckoutSignature);

        $this->selfCheckoutCode = '';
        $this->selfCheckoutVisitorId = null;
        $this->selfCheckoutSignature = '';
        $this->selfCheckoutMessage = 'Checkout complete. Thank you.';
        $this->dispatch('kiosk-clear-signature', property: 'selfCheckoutSignature');
        $this->dispatch('toast', type: 'success', message: $this->selfCheckoutMessage);
    }

    public function render()
    {
        $employees = Employee::query()
            ->active()
            ->visibleInErp()
            ->with(['department', 'district.region'])
            ->orderBy('full_name')
            ->get(['id', 'staff_id', 'full_name', 'department_id', 'district_id']);

        return view('livewire.visitors.kiosk', [
            'employeeOptions' => $employees
                ->map(fn (Employee $employee) => [
                    'value' => $employee->id,
                    'label' => $employee->full_name,
                    'description' => collect([
                        $employee->staff_id,
                        $employee->department?->department_name,
                        $employee->district?->district_name,
                    ])->filter()->join(' - '),
                ])
                ->all(),
            'selfCheckoutVisitor' => $this->selfCheckoutVisitorId ? Visitor::find($this->selfCheckoutVisitorId) : null,
        ]);
    }

    protected function messages(): array
    {
        return [
            'phone.required' => 'Please enter your phone number.',
            'phone.digits' => 'Please enter a 10-digit phone number.',
            'staff_id.required' => 'Please select the employee you are visiting.',
            'signature.required' => 'Signature is required.',
        ];
    }
}
