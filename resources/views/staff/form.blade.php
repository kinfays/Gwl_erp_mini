<x-erp-layout module="staff" title="{{ $employee ? 'Edit Employee' : 'Add Employee' }}">
    <div class="content">
        <livewire:staff.employee-form :employee="$employee" />
    </div>
</x-erp-layout>
