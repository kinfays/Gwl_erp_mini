<x-erp-layout module="staff" title="Staff Management">
    <div class="content">
        <livewire:staff.all-employees />
    </div>

    <x-uac.user-drawer :url-base="url('/staff/users')" />
</x-erp-layout>
