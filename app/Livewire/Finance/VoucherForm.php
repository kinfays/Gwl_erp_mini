<?php

namespace App\Livewire\Finance;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\Finance\Concerns\ScopesFinanceByActor;
use App\Models\District;
use App\Models\FinPaymentVoucher as Pv;
use App\Models\FinSupplier;
use App\Models\Permission;
use App\Services\Finance\FinanceScope;
use App\Services\Finance\PvCatalogService;
use App\Services\Finance\SupplierService;
use App\Services\GWater\Money;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Creates or edits a payment voucher (a Draft, or one Audit queried). The form passes the lock_version it opened; a newer one
 * raises a conflict warning and nothing is overwritten. Every write is PaymentVoucherWorkflowService's.
 */
class VoucherForm extends Component
{
    use EnforcesModuleAccess;
    use ScopesFinanceByActor;
    use WithFileUploads;

    public ?int $pvId = null;

    public int $version = 0;

    public array $form = [
        'pv_date' => '', 'pv_no' => '', 'payee_kind' => 'employee', 'payee_employee_id' => '', 'payee_district_id' => '',
        'supplier_id' => '', 'category_id' => '', 'type_id' => '', 'details' => '', 'amount' => '', 'remarks' => '',
    ];

    /** Finance's reply to Audit when a queried voucher is resubmitted (kept on the timeline, optional). */
    public string $note = '';

    /** Bumped when the page itself picks a payee (a supplier just added), so the picker is rebuilt showing it. */
    public int $pickerNonce = 0;

    public bool $showSupplierAdd = false;

    public array $uploads = [];

    /** @var array<int, int> attachments ticked for removal */
    public array $removeIds = [];

    /** The "add a supplier" panel (company-wide list, SupplierService): typed values and "this is a different supplier". */
    public array $newSupplier = ['name' => '', 'code' => '', 'tin' => '', 'phone' => ''];

    public bool $differentSupplier = false;

    public function mount(?int $pv = null): void
    {
        $this->enforceFinance(Permission::MODULE_FINANCE);
        $this->guard('finance.create_pv');

        if ($pv === null) {
            $this->form['pv_date'] = today()->toDateString();

            return;
        }

        $this->load($pv);
    }

    public function load(int $id): void
    {
        $voucher = $this->pvOrFail($id, FinanceScope::AS_FINANCE);

        if (! $voucher->isEditable()) {
            session()->flash('error', 'That voucher can no longer be edited.');
            $this->redirectRoute('finance.pvs.show', $voucher->id);

            return;
        }

        $this->pvId = $voucher->id;
        $this->version = $voucher->lock_version;
        $this->form = [
            'pv_date' => $voucher->pv_date->toDateString(),
            'pv_no' => $voucher->pv_no,
            'payee_kind' => $voucher->payee_kind,
            'payee_employee_id' => (string) ($voucher->payee_employee_id ?? ''),
            'payee_district_id' => (string) ($voucher->payee_district_id ?? ''),
            'supplier_id' => (string) ($voucher->supplier_id ?? ''),
            'category_id' => (string) $voucher->category_id,
            'type_id' => (string) $voucher->type_id,
            'details' => $voucher->details,
            'amount' => Money::input($voucher->amount),
            'remarks' => (string) $voucher->remarks,
        ];
        $this->uploads = [];
        $this->removeIds = [];
        $this->resetErrorBag();
    }

    /** After a conflict warning: throw the form away and open the latest version. */
    public function reload(): void
    {
        if ($this->pvId) {
            $this->load($this->pvId);
        }
    }

    public function updatedFormCategoryId(): void
    {
        $this->form['type_id'] = '';
    }

    /** Choosing who is paid FIRST: changing the kind throws the picked payee away (a person is never kept as a supplier). */
    public function updatedFormPayeeKind(): void
    {
        $this->clearPayee();
    }

    public function updatedLocationId(): void
    {
        $this->clearPayee();
    }

    protected function clearPayee(): void
    {
        $this->form['payee_employee_id'] = '';
        $this->form['payee_district_id'] = '';
        $this->form['supplier_id'] = '';
        $this->pickerNonce++;
        $this->resetErrorBag(['form.payee_employee_id', 'form.payee_district_id', 'form.supplier_id']);
    }

    // ------------------------------------------------------------------ the payee pickers (server-side search)

    /**
     * Current staff or retirees of THIS voucher's location for the picker: called by the browser with the typed text. Scoped,
     * 2+ characters, 20 rows at most, never a phone number (FinanceScope::searchEmployees).
     *
     * @return list<array{value: int, label: string, description: string}>
     */
    public function searchPeople(string $term): array
    {
        $this->guard('finance.create_pv');
        $kind = $this->form['payee_kind'];

        if (! in_array($kind, [Pv::PAYEE_EMPLOYEE, Pv::PAYEE_RETIREE], true)) {
            return [];
        }

        return $this->scope()->searchEmployees($this->actor(), $kind, $term, $this->payeeLocation($this->currentVoucher()));
    }

    /** @return list<array{value: int, label: string, description: string}> active suppliers (company-wide), never a TIN or phone */
    public function searchSuppliers(string $term): array
    {
        $this->guard('finance.create_pv');

        return $this->form['payee_kind'] === Pv::PAYEE_SUPPLIER ? app(SupplierService::class)->search($term) : [];
    }

    /** "No match: add it as a new supplier": opens the quick-add panel with the typed name (only for those who may add suppliers). */
    public function startNewSupplier(string $name): void
    {
        $this->guard('finance.manage_suppliers');
        $this->resetErrorBag(['newSupplier.name', 'newSupplier.code', 'newSupplier.tin', 'newSupplier.phone']);
        $this->newSupplier['name'] = mb_substr(trim($name), 0, 200);
        $this->differentSupplier = false;
        $this->showSupplierAdd = true;
    }

    /** The voucher being edited, found inside the user's reach (the id is a public property the browser could change). */
    protected function currentVoucher(): ?Pv
    {
        return $this->pvId ? $this->pvs(FinanceScope::AS_FINANCE)->find($this->pvId) : null;
    }

    /** @return array{region_id: int|null, district_id: int|null}|null */
    protected function payeeLocation(?Pv $existing): ?array
    {
        return $existing
            ? ['region_id' => $existing->region_id, 'district_id' => $existing->district_id]
            : $this->workingLocation();
    }

    public function saveDraft()
    {
        return $this->persist(false);
    }

    public function saveAndSend()
    {
        return $this->persist(true);
    }

    protected function persist(bool $send)
    {
        $this->guard('finance.create_pv');
        $this->resetErrorBag();

        $workflow = $this->workflow();
        $pv = $this->run(function () use ($workflow, $send) {
            if ($this->pvId === null) {
                $created = $workflow->createDraft($this->actor(), $this->form, $this->uploads, $this->recordingDistrict());

                return $send ? $workflow->submit($this->actor(), $created, $created->lock_version) : $created;
            }

            $saved = $workflow->update($this->actor(), $this->pvId, $this->form, $this->version, $this->uploads, $this->removeIds);

            if (! $send) {
                return $saved;
            }

            return $saved->status === Pv::QUERIED
                ? $workflow->resubmit($this->actor(), $saved, $saved->lock_version, $this->note)
                : $workflow->submit($this->actor(), $saved, $saved->lock_version);
        });

        if (! $pv) {
            return null; // a version conflict: the warning is shown
        }

        session()->flash('status', $send ? 'Sent to Audit.' : 'Saved.');

        return $this->redirectRoute('finance.pvs.show', $pv->id);
    }

    public function updatedNewSupplierName(): void
    {
        $this->differentSupplier = false;
    }

    /** Adds a supplier to the company-wide list and picks it for this voucher. */
    public function addSupplier(SupplierService $suppliers): void
    {
        $this->guard('finance.manage_suppliers');
        $this->resetErrorBag(['newSupplier.name', 'newSupplier.code', 'newSupplier.tin', 'newSupplier.phone']);
        $supplier = $this->run(fn () => $suppliers->create($this->actor(), $this->newSupplier, $this->differentSupplier), 'newSupplier');

        $this->newSupplier = ['name' => '', 'code' => '', 'tin' => '', 'phone' => ''];
        $this->differentSupplier = false;
        $this->showSupplierAdd = false;
        $this->pickerNonce++;
        $this->form['supplier_id'] = (string) $supplier->id;
        $this->dispatch('toast', type: 'success', message: 'Supplier added and chosen.');
    }

    public function removeUpload(int $index): void
    {
        unset($this->uploads[$index]);
        $this->uploads = array_values($this->uploads);
    }

    /**
     * The name to show in the picker for the payee already chosen, worked out inside the user's reach (an id from the browser is
     * never turned into a name unless it is one the person could pick; a voucher being edited keeps its own payee).
     */
    protected function payeeLabel(?Pv $existing, ?array $location): string
    {
        $kind = $this->form['payee_kind'];
        $same = fn (string $column, int $id) => $existing && $existing->payee_kind === $kind && (int) $existing->{$column} === $id;

        if (in_array($kind, [Pv::PAYEE_EMPLOYEE, Pv::PAYEE_RETIREE], true)) {
            $id = (int) $this->form['payee_employee_id'];
            $person = $id > 0 ? $this->scope()->employees($this->actor(), $kind, $location)->whereKey($id)->first() : null;
            $person ??= $same('payee_employee_id', $id) ? \App\Models\Employee::query()->find($id) : null;

            return (string) $person?->full_name;
        }

        if ($kind === Pv::PAYEE_DISTRICT) {
            $id = (int) $this->form['payee_district_id'];
            $district = $id > 0 ? $this->scope()->districts($this->actor(), $location)->whereKey($id)->first() : null;
            $district ??= $same('payee_district_id', $id) ? District::query()->find($id) : null;

            return (string) $district?->district_name;
        }

        $id = (int) $this->form['supplier_id'];
        $supplier = $id > 0 ? FinSupplier::query()->whereKey($id)->where(fn ($query) => $query->where('is_active', true)->when($same('supplier_id', $id), fn ($inner) => $inner->orWhere('id', $id)))->first() : null;

        return (string) $supplier?->name;
    }

    public function render(SupplierService $supplierService, PvCatalogService $catalogue)
    {
        // Resolved inside the user's reach every time: the id is a public property the browser could change.
        $existing = $this->currentVoucher();
        $kind = $this->form['payee_kind'];
        $location = $this->payeeLocation($existing);

        // Only the location's active categories and types (PvCatalogService works them out); a voucher being edited keeps its own.
        ['categories' => $categories, 'types' => $types] = $catalogue->choices($location, (int) $this->form['category_id'], $existing);

        // The districts of this location only; a district an old voucher already has stays in its own list so it can be shown.
        $districts = $this->scope()->districts($this->actor(), $location)->get();
        $kept = $existing?->payee_kind === Pv::PAYEE_DISTRICT ? (int) $existing->payee_district_id : 0;

        if ($kept && ! $districts->contains('id', $kept) && ($old = District::query()->find($kept))) {
            $districts->push($old);
        }

        return view('livewire.finance.voucher-form', [
            'existing' => $existing,
            'categories' => $categories,
            'types' => $types,
            'districtOptions' => $kind === Pv::PAYEE_DISTRICT
                ? $districts->map(fn (District $district) => ['value' => $district->id, 'label' => $district->district_name])->all()
                : [],
            'payeeLabel' => $this->payeeLabel($existing, $location),
            'payeeKinds' => Pv::PAYEE_KINDS,
            'attachments' => $existing ? $existing->attachments()->get() : collect(),
            'canManageSuppliers' => $this->can('finance.manage_suppliers'),
            'supplierMatches' => $kind === Pv::PAYEE_SUPPLIER && trim($this->newSupplier['name']) !== '' ? $supplierService->nearMatches($this->newSupplier['name']) : [],
            'isResubmission' => $existing?->status === Pv::QUERIED,
            'maxFiles' => (int) config('gwl.fin_attachment_max_per_pv'),
            'maxMb' => (int) config('gwl.fin_attachment_max_mb'),
        ] + $this->locationView());
    }
}
