{{-- Excel / PDF of the report on screen: only when the user holds gwater.export and gwater.view_reports (the component passes null otherwise). --}}
@if (! empty($exports))
    <div class="ui-form-actions dash-row" style="justify-content:flex-end">
        <x-ui.button size="sm" :href="$exports['excel']" icon="file-spreadsheet">Excel</x-ui.button>
        <x-ui.button size="sm" :href="$exports['pdf']" icon="file-text">PDF</x-ui.button>
    </div>
@endif
