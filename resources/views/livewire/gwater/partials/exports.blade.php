{{-- Excel / PDF of the report on screen: only when the user holds gwater.export and gwater.view_reports (the component passes null otherwise). --}}
@if (! empty($exports))
    <div class="ui-form-actions dash-row" style="justify-content:flex-end">
        <x-ui.button size="sm" :href="$exports['excel']" file="excel">Excel</x-ui.button>
        <x-ui.button size="sm" :href="$exports['pdf']" file="pdf">PDF</x-ui.button>
    </div>
@endif
