<div>
    @php
        $money = fn ($value) => $value === null ? '–' : 'GH¢ '.number_format($value, 2);
        $date = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->format('d M Y') : '–';
    @endphp

    <x-ui.page-header :title="'Account '.$customer['account_no']" description="One customer: the figures from the latest file, and how the account has changed from upload to upload.">
        <x-slot:actions>
            <a href="{{ route('commercial.customers.list', ['district' => \Illuminate\Support\Facades\DB::table('commercial_customers')->where('id', $customer['id'])->value('district_id')]) }}" class="btn btn-secondary">Back to the list</a>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($customer['missing'])
        <x-ui.alert tone="warning" class="dash-row">This account was in an earlier file but is not in the latest one. It is kept, not deleted.</x-ui.alert>
    @endif

    <div class="ui-grid ui-grid-2 dash-row">
        <x-ui.card title="Account">
            <dl class="ui-dl">
                <dt>District / route</dt><dd>{{ $customer['district'] }} · {{ $customer['route'] }}</dd>
                <dt>Category</dt><dd>{{ $customer['category'] }}</dd>
                <dt>Status</dt><dd>{{ $customer['status'] }}</dd>
                <dt>Meter</dt><dd>{{ $customer['meter_status'] }}@if ($customer['meter_no']) · <span class="mono">{{ $customer['meter_no'] }}</span>@endif</dd>
                <dt>Connected</dt><dd>{{ $date($customer['connect_date']) }}</dd>
                <dt>Balance</dt><dd><strong>{{ $money($customer['balance']) }}</strong> · {{ $customer['bucket'] }} <span class="ui-hint">(sign convention to be confirmed)</span></dd>
                <dt>Last bill</dt><dd>{{ $date($customer['last_bill_date']) }} · {{ $money($customer['last_bill_amount']) }}</dd>
                <dt>Last payment</dt><dd>{{ $date($customer['last_paid_date']) }} · {{ $money($customer['last_paid_amount']) }}</dd>
                <dt>Last read</dt><dd>{{ $date($customer['last_read_date']) }}</dd>
            </dl>
        </x-ui.card>

        <x-ui.card title="Contact details">
            @if (! $details)
                <p class="ui-hint">Names, addresses, phone numbers and e-mails are personal data. You do not hold the permission to see them.</p>
            @elseif (! $contact)
                <p class="ui-hint">No contact details are on file for this account.</p>
            @else
                <dl class="ui-dl">
                    <dt>Name</dt><dd>{{ $contact['name'] ?? '–' }}</dd>
                    <dt>Address</dt><dd>{{ $contact['address'] ?? '–' }}</dd>
                    <dt>Mobile</dt><dd class="mono">{{ $contact['mobiles'] ?: '–' }}</dd>
                    <dt>E-mail</dt><dd>{{ $contact['email'] ?? '–' }}</dd>
                </dl>
                @if ($revealed)
                    <button type="button" class="btn btn-secondary btn-sm" wire:click="hide">Mask again</button>
                    <p class="ui-hint">Showing these details was recorded in the audit log.</p>
                @else
                    <button type="button" class="btn btn-secondary btn-sm" wire:click="reveal" wire:confirm="Show the full phone number and e-mail? This is recorded in the audit log.">Show full contact details</button>
                @endif
            @endif
        </x-ui.card>
    </div>

    <x-ui.card title="History" description="What each upload changed for this account." :padded="false" class="dash-row">
        <x-ui.table label="Account history" :sticky="false">
            <x-slot:head><tr><th>File as of</th><th>Change</th></tr></x-slot:head>
            @forelse ($history as $row)
                <tr wire:key="h-{{ $loop->index }}"><td>{{ $date($row['date']) }}</td><td>{{ $row['what'] }}</td></tr>
            @empty
                <x-ui.empty-row :colspan="2" icon="history" title="Nothing has changed since it was first seen." />
            @endforelse
        </x-ui.table>
    </x-ui.card>
</div>
