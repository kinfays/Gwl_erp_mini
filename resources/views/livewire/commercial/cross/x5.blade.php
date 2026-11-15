{{-- X5: billing against the customer list --}}
@if ($parts['link']['data'] === null)
    <x-commercial.cross-part :part="$parts['link']" />
@else
<x-commercial.cross-part :part="$parts['link']" description="Worked out once, when the Detailed Billing Report was loaded and linked to the customer list: each account's status and service category as billed against the list's own as it was then. Accounts the list no longer holds are not compared.">
    @php $d = $parts['link']['data']; @endphp
    <div class="ui-stat-grid dash-row">
        <x-ui.stat-tile label="Accounts compared" :value="number_format($d['totals']['compared'])" icon="layers" />
        <x-ui.stat-tile label="Status changed" :value="number_format($d['totals']['status_changed'])" icon="activity" tone="warning" meta="between the bill and the list" />
        <x-ui.stat-tile label="Category changed" :value="number_format($d['totals']['category_changed'])" icon="activity" meta="service category" />
    </div>

    @include('livewire.commercial.cross.table', ['label' => 'Accounts in billing and not in the list', 'rows' => $d['accounts'], 'cols' => [['District of the billing route', 'district'], ['Accounts in billing', 'accounts', 'n'], ['Found in the list', 'in_list', 'n'], ['Not in the list', 'not_in_list', 'n']], 'empty' => 'No account was compared.'])
    @include('livewire.commercial.cross.table', ['label' => 'Billing-status accounts in the list and not in the billing file', 'rows' => $d['list_not_in_file'], 'cols' => [['District the list puts them in', 'district'], ['Accounts', 'accounts', 'n']], 'empty' => 'None: every billing-status account of the list is in the file.'])
    @if ($d['region_totals'])
        <p class="ui-hint">Whole file: {{ number_format($d['region_totals']['accounts']) }} accounts, {{ number_format($d['region_totals']['not_in_customer_list']) }} not in the list; {{ number_format($d['region_totals']['list_accounts_not_in_file']) }} list accounts (any status) of these districts not in the file.</p>
    @endif

    <h4 style="margin-top:16px">Status: from the bill to the list</h4>
    @include('livewire.commercial.cross.table', ['label' => 'Status from and to', 'rows' => $d['matrix']['status'], 'cap' => 200, 'cols' => [['District', 'district'], ['Status on the bill', 'from'], ['Status in the list', 'to'], ['Accounts', 'customers', 'n'], ['Moved', 'moved_text']]])
    <h4 style="margin-top:16px">Service category: from the bill to the list</h4>
    @include('livewire.commercial.cross.table', ['label' => 'Category from and to', 'rows' => $d['matrix']['category'], 'cap' => 200, 'cols' => [['District', 'district'], ['Category on the bill', 'from'], ['Category in the list', 'to'], ['Accounts', 'customers', 'n'], ['Moved', 'moved_text']]])

    <h4 style="margin-top:16px">Accounts whose status moved</h4>
    <p class="ui-hint">Account numbers only (each upload keeps at most {{ number_format((int) config('gwl.commercial_billing_detail_issue_cap', 200)) }} per district); a name appears only on the customer view, behind its own permission.</p>
    @include('livewire.commercial.cross.table', ['label' => 'Accounts whose status moved', 'rows' => $d['changed_accounts'], 'cap' => 50, 'cols' => [['Account', 'account'], ['Route', 'route', 't', 'route_id'], ['District', 'district']], 'routeQuery' => $routeQuery])
</x-commercial.cross-part>
@endif
