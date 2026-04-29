<?php

namespace App\Livewire\Visitors;

use App\Models\Employee;
use App\Models\Visitor;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

class Kiosk extends Component
{
    public int $step = 1;
    public string $visitor_name = '';
    public string $phone = '';
    public int|string $staff_id = '';
    public string $employeeSearch = '';
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

    public function checkDuplicate(): void
    {
        $name = trim($this->visitor_name);

        $this->duplicateWarning = $name !== ''
            && Visitor::query()
                ->today()
                ->inside()
                ->whereRaw('LOWER(visitor_name) = ?', [mb_strtolower($name)])
                ->exists();
    }

    public function next(): void
    {
        if ($this->step === 1) {
            $this->validate([
                'visitor_name' => ['required', 'string', 'max:255'],
                'phone' => ['nullable', 'string', 'max:50'],
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
            'phone' => ['nullable', 'string', 'max:50'],
            'staff_id' => ['required', 'exists:employees,id'],
            'purpose' => ['nullable', 'string', 'max:1000'],
            'signature' => ['required', 'string'],
        ]);

        $visitor = Visitor::create([
            ...$validated,
            'checkout_code' => $this->makeCheckoutCode(),
            'check_in_at' => now(),
        ]);

        $this->checkoutCode = $visitor->checkout_code;
        $this->successName = $visitor->visitor_name;
        $this->success = true;
    }

    public function resetKiosk(): void
    {
        $this->reset([
            'step',
            'visitor_name',
            'phone',
            'staff_id',
            'employeeSearch',
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

        $visitor = Visitor::query()
            ->today()
            ->inside()
            ->where('checkout_code', $code)
            ->latest()
            ->first();

        if (! $visitor) {
            $this->selfCheckoutVisitorId = null;
            $this->selfCheckoutMessage = 'No active visit was found for that code.';
            return;
        }

        $this->selfCheckoutVisitorId = $visitor->id;
        $this->selfCheckoutMessage = '';
    }

    public function confirmSelfCheckout(): void
    {
        $visitor = Visitor::query()
            ->today()
            ->inside()
            ->findOrFail($this->selfCheckoutVisitorId);

        $visitor->update([
            'signature' => $this->selfCheckoutSignature ?: $visitor->signature,
            'check_out_at' => now(),
            'checked_out_by' => 'self',
        ]);

        $this->selfCheckoutCode = '';
        $this->selfCheckoutVisitorId = null;
        $this->selfCheckoutSignature = '';
        $this->selfCheckoutMessage = 'Checkout complete. Thank you.';
    }

    public function render()
    {
        $employees = collect();

        if (mb_strlen(trim($this->employeeSearch)) >= 2) {
            $employees = Employee::query()
                ->active()
                ->visibleInErp()
                ->with(['department', 'district.region'])
                ->where(function (Builder $query) {
                    $query
                        ->where('full_name', 'like', '%' . $this->employeeSearch . '%')
                        ->orWhere('staff_id', 'like', '%' . $this->employeeSearch . '%')
                        ->orWhere('email', 'like', '%' . $this->employeeSearch . '%');
                })
                ->orderBy('full_name')
                ->limit(25)
                ->get();
        }

        return view('livewire.visitors.kiosk', [
            'employees' => $employees,
            'selectedEmployee' => $this->staff_id ? Employee::with(['department', 'district.region'])->find($this->staff_id) : null,
            'selfCheckoutVisitor' => $this->selfCheckoutVisitorId ? Visitor::find($this->selfCheckoutVisitorId) : null,
        ]);
    }

    protected function makeCheckoutCode(): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $digits = random_int(1, 3);
            $code = (string) random_int(10 ** ($digits - 1), (10 ** $digits) - 1);

            $exists = Visitor::query()
                ->today()
                ->inside()
                ->where('checkout_code', $code)
                ->exists();

            if (! $exists) {
                return $code;
            }
        }

        return (string) random_int(100, 999);
    }
}
