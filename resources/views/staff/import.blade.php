<x-erp-layout module="staff" title="Staff Import and Export">
    <div class="content">
        @include('shared.import-workspace', [
            'context' => 'staff',
            'title' => 'Import / Export',
            'description' => 'Upload employee and staff structure data using the shared import pipeline.',
            'defaultType' => 'employees',
            'showUsersType' => false,
        ])
    </div>
</x-erp-layout>
