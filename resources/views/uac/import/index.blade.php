<x-uac-layout>
    @include('shared.import-workspace', [
        'context' => 'uac',
        'title' => 'Bulk Import Workspace',
        'description' => 'Prepare Excel uploads for staff structures and user-role assignments.',
        'defaultType' => 'users',
        'showUsersType' => true,
        'availableImportTypes' => $availableImportTypes ?? null,
    ])
</x-uac-layout>
