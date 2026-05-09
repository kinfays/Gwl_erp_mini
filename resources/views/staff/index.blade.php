<x-erp-layout module="staff" title="Staff Management">
    <div class="content">
        <livewire:staff.all-employees />
    </div>
</x-erp-layout>
<x-uac.user-drawer :url-base="url('/staff/users')" />
