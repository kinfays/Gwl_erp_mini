<x-erp-layout module="assets" :title="$rack ? 'Edit Rack' : 'Add Rack'">
    <div class="content">
        <livewire:assets.racks.rack-form :rack="$rack" />
    </div>
</x-erp-layout>
