<?php

namespace App\Livewire\Finance;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\Finance\Concerns\ScopesFinanceByActor;
use App\Models\District;
use App\Models\FinPaymentVoucher as Pv;
use App\Models\FinPvCategory;
use App\Models\FinPvType;
use App\Models\FinSupplier;
use App\Models\Permission;
use App\Services\Finance\FinanceScope;
use App\Services\Finance\PvCatalogService;
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

    public string $payeeSearch = '';

    public array $uploads = [];

    /** @var array<int, int> attachments ticked for removal */
    public array $removeIds = [];

    public string $newCategory = '';

    public string $newType = '';

    public bool $showCatalogue = false;

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

    public function updatedFormPayeeKind(): void
    {
        $this->form['payee_employee_id'] = '';
        $this->form['payee_district_id'] = '';
        $this->form['supplier_id'] = '';
        $this->payeeSearch = '';
    }

    public function updatedLocationId(): void
    {
        $this->form['payee_employee_id'] = '';
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

    public function addCategory(PvCatalogService $catalogue): void
    {
        $this->guard('finance.manage_categories');
        $category = $this->run(fn () => $catalogue->createCategory($this->actor(), $this->newCategory), 'catalogue');
        $this->newCategory = '';
        $this->form['category_id'] = (string) $category->id;
        $this->form['type_id'] = '';
        $this->dispatch('toast', type: 'success', message: 'Category added.');
    }

    public function addType(PvCatalogService $catalogue): void
    {
        $this->guard('finance.manage_categories');
        $type = $this->run(fn () => $catalogue->createType($this->actor(), (int) $this->form['category_id'], $this->newType), 'catalogue');
        $this->newType = '';
        $this->form['type_id'] = (string) $type->id;
        $this->dispatch('toast', type: 'success', message: 'Type added.');
    }

    public function removeUpload(int $index): void
    {
        unset($this->uploads[$index]);
        $this->uploads = array_values($this->uploads);
    }

    public function render()
    {
        // Resolved inside the user's reach every time: the id is a public property the browser could change.
        $existing = $this->pvId ? $this->pvs(FinanceScope::AS_FINANCE)->find($this->pvId) : null;
        $kind = $this->form['payee_kind'];
        $location = $this->pvId && $existing
            ? ['region_id' => $existing->region_id, 'district_id' => $existing->district_id]
            : $this->workingLocation();
        $search = trim($this->payeeSearch);

        $employees = collect();

        if (in_array($kind, [Pv::PAYEE_EMPLOYEE, Pv::PAYEE_RETIREE], true)) {
            $query = $this->scope()->employees($this->actor(), $kind, $location);
            $selected = (int) $this->form['payee_employee_id'];

            $employees = (clone $query)
                ->when($search !== '', fn ($builder) => $builder->where(function ($inner) use ($search) {
                    $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
                    $inner->where('employees.full_name', 'like', $like)->orWhere('employees.staff_id', 'like', $like);
                }))
                ->limit(25)->get();

            if ($selected && ! $employees->contains('id', $selected)) {
                $current = $existing?->payee_employee_id === $selected
                    ? \App\Models\Employee::query()->find($selected)
                    : (clone $query)->whereKey($selected)->first();
                $employees = $current ? $employees->prepend($current) : $employees;
            }
        }

        $categoryId = (int) $this->form['category_id'];
        $categories = FinPvCategory::query()
            ->where(fn ($query) => $query->where('is_active', true)->when($existing, fn ($inner) => $inner->orWhere('id', $existing->category_id)))
            ->orderBy('sort_order')->orderBy('name')->get();
        $types = $categoryId
            ? FinPvType::query()->where('category_id', $categoryId)
                ->where(fn ($query) => $query->where('is_active', true)->when($existing, fn ($inner) => $inner->orWhere('id', $existing->type_id)))
                ->orderBy('sort_order')->orderBy('name')->get()
            : collect();

        $suppliers = $kind === Pv::PAYEE_SUPPLIER
            ? FinSupplier::query()
                ->where(fn ($query) => $query->where('is_active', true)->when($existing?->supplier_id, fn ($inner) => $inner->orWhere('id', $existing->supplier_id)))
                ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%'))
                ->orderBy('name')->limit(50)->get()
            : collect();

        return view('livewire.finance.voucher-form', [
            'existing' => $existing,
            'employees' => $employees,
            'categories' => $categories,
            'types' => $types,
            'suppliers' => $suppliers,
            'districts' => $kind === Pv::PAYEE_DISTRICT ? District::query()->orderBy('district_name')->get() : collect(),
            'payeeKinds' => Pv::PAYEE_KINDS,
            'attachments' => $existing ? $existing->attachments()->get() : collect(),
            'canManageCatalogue' => $this->can('finance.manage_categories'),
            'isResubmission' => $existing?->status === Pv::QUERIED,
            'maxFiles' => (int) config('gwl.fin_attachment_max_per_pv'),
            'maxMb' => (int) config('gwl.fin_attachment_max_mb'),
        ] + $this->locationView());
    }
}
