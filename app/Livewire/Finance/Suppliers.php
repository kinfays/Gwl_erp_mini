<?php

namespace App\Livewire\Finance;

use App\Livewire\Concerns\EnforcesModuleAccess;
use App\Livewire\Finance\Concerns\ScopesFinanceByActor;
use App\Models\FinSupplier;
use App\Models\Permission;
use App\Services\Finance\FinanceScope;
use App\Services\Finance\SupplierDirectory;
use App\Services\Finance\SupplierService;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The company-wide supplier list, read by Finance and by Audit (`module`), changed only through SupplierService and only from the
 * Finance door (finance.manage_suppliers to add / edit, finance.deactivate_suppliers to switch one off or on). The list is the same
 * for everyone; the figures beside a supplier come from SupplierDirectory, which asks FinanceScope, so a location never sees what
 * another location paid.
 */
class Suppliers extends Component
{
    use EnforcesModuleAccess;
    use ScopesFinanceByActor;
    use WithPagination;

    /** 'finance' or 'audit': the door the user came through. */
    public string $module = 'finance';

    public string $search = '';

    public string $status = 'active';

    public bool $showForm = false;

    public ?int $editingId = null;

    public array $form = ['name' => '', 'code' => '', 'tin' => '', 'phone' => ''];

    /** "This is a different supplier": the person has seen the look-alikes. */
    public bool $differentSupplier = false;

    public ?int $viewingId = null;

    public function mount(string $module = 'finance'): void
    {
        $this->module = $module === 'audit' ? 'audit' : 'finance';
        $this->enforceFinance($this->module === 'audit' ? Permission::MODULE_AUDIT : Permission::MODULE_FINANCE);
        $this->guard($this->module.'.view');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status'], true)) {
            $this->resetPage();
        }

        if ($property === 'form.name') {
            $this->differentSupplier = false;
        }
    }

    public function startAdd(): void
    {
        $this->guardManage();
        $this->openForm(null, ['name' => '', 'code' => '', 'tin' => '', 'phone' => '']);
    }

    public function startEdit(int $id): void
    {
        $this->guardManage();
        $supplier = FinSupplier::query()->findOrFail($id);
        $this->openForm($supplier->id, ['name' => $supplier->name, 'code' => (string) $supplier->code, 'tin' => (string) $supplier->tin, 'phone' => (string) $supplier->phone]);
    }

    public function cancel(): void
    {
        $this->showForm = false;
        $this->editingId = null;
        $this->differentSupplier = false;
        $this->resetErrorBag();
    }

    public function save(SupplierService $suppliers): void
    {
        $this->guardManage();

        $this->run(fn () => $this->editingId
            ? $suppliers->update($this->actor(), $this->editingId, $this->form, $this->differentSupplier)
            : $suppliers->create($this->actor(), $this->form, $this->differentSupplier));

        $this->dispatch('toast', type: 'success', message: $this->editingId ? 'Supplier saved.' : 'Supplier added.');
        $this->cancel();
    }

    public function toggleActive(SupplierService $suppliers, int $id, bool $active): void
    {
        $this->guard('finance.deactivate_suppliers');
        abort_unless($this->module === 'finance', 403);

        $this->run(fn () => $suppliers->setActive($this->actor(), $id, $active), 'supplier');
        $this->dispatch('toast', type: 'success', message: $active ? 'Supplier switched on.' : 'Supplier switched off.');
    }

    public function view(int $id): void
    {
        $this->viewingId = $this->viewingId === $id ? null : $id;
    }

    protected function guardManage(): void
    {
        abort_unless($this->module === 'finance', 403);
        $this->guard('finance.manage_suppliers');
    }

    /** @param  array<string, string>  $form */
    protected function openForm(?int $id, array $form): void
    {
        $this->editingId = $id;
        $this->form = $form;
        $this->differentSupplier = false;
        $this->showForm = true;
        $this->resetErrorBag();
    }

    public function render(SupplierDirectory $directory, SupplierService $suppliers)
    {
        $as = $this->module === 'audit' ? FinanceScope::AS_AUDIT : FinanceScope::AS_FINANCE;
        $rows = $directory->suppliers($this->search, $this->status)->paginate(20);
        $viewing = $this->viewingId ? FinSupplier::query()->find($this->viewingId) : null;

        return view('livewire.finance.suppliers', [
            'rows' => $rows,
            'figures' => $directory->figures($this->actor(), $as, $rows->pluck('id')->all()),
            'viewing' => $viewing,
            'recent' => $viewing ? $directory->recentVouchers($this->actor(), $as, $viewing->id) : collect(),
            'matches' => $this->showForm ? $suppliers->nearMatches((string) $this->form['name'], $this->editingId) : [],
            'canManage' => $this->module === 'finance' && $this->can('finance.manage_suppliers'),
            'canDeactivate' => $this->module === 'finance' && $this->can('finance.deactivate_suppliers'),
            'canImport' => $this->module === 'finance' && $this->can('finance.import_suppliers'),
            'showRoute' => $this->module === 'audit' ? 'audit.pvs.show' : 'finance.pvs.show',
            'seesAllLocations' => $this->isSuperAdmin(),
        ]);
    }
}
