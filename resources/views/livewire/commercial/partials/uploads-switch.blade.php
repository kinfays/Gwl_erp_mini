{{-- One place for both kinds of upload: the meter-reading / billing reports and the customer list. Expects $current ('reports' or 'customers'). --}}
@if (config('gwl.commercial_customer_list_enabled'))
    <nav class="ui-segmented dash-row" aria-label="Kind of upload" style="display:inline-flex;gap:.25rem;margin-bottom:1rem">
        <a href="{{ route('commercial.batches') }}" class="btn {{ $current === 'reports' ? 'btn-primary' : 'btn-secondary' }}" @if ($current === 'reports') aria-current="page" @endif>Reading &amp; billing reports</a>
        <a href="{{ route('commercial.customers.uploads') }}" class="btn {{ $current === 'customers' ? 'btn-primary' : 'btn-secondary' }}" @if ($current === 'customers') aria-current="page" @endif>Customer list</a>
    </nav>
@endif
