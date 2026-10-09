<?php

namespace App\Livewire\Commercial\Customers;

use App\Livewire\Commercial\Concerns\ScopesCommercialByActor;
use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Models\CommercialCustomer;
use App\Models\Permission;
use App\Services\Commercial\Customers\CustomerListService;
use App\Support\Audit;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One customer: the account's figures, what changed upload by upload and, for holders of commercial.view_customer_details,
 * the contact details MASKED. "Show contact details" unmasks them and is audited (customer id only, never a value).
 * A customer outside the viewer's region answers 404 exactly like one that does not exist.
 */
class Show extends Component
{
    use EnforcesModuleAccess;
    use ScopesCommercialByActor;

    #[Locked]
    public int $customerId;

    public bool $revealed = false;

    public function mount(int $customer): void
    {
        $this->enforceLivewireModule(Permission::MODULE_COMMERCIAL);
        $this->guardCustomerList();
        $this->guardCommercialPermission('commercial.view_customer_analytics');

        $this->customerId = $customer;

        abort_if(app(CustomerListService::class)->find($customer, $this->customerRestriction()) === null, 404);
    }

    public function reveal(): void
    {
        $this->guardCommercialPermission('commercial.view_customer_details');
        abort_if(app(CustomerListService::class)->find($this->customerId, $this->customerRestriction()) === null, 404);

        $this->revealed = true;

        Audit::log(
            action: 'commercial.customer_contact_revealed',
            module: Permission::MODULE_COMMERCIAL,
            targetType: CommercialCustomer::class,
            targetId: $this->customerId,
        );
    }

    public function hide(): void
    {
        $this->revealed = false;
    }

    public function render(CustomerListService $lists)
    {
        $customer = $lists->find($this->customerId, $this->customerRestriction());
        abort_if($customer === null, 404);

        $details = $this->customerDetails();

        return view('livewire.commercial.customers.show', [
            'customer' => $customer,
            'history' => $lists->history($this->customerId),
            'contact' => $details ? $lists->contact($this->customerId, $this->revealed) : null,
            'details' => $details,
        ]);
    }
}
