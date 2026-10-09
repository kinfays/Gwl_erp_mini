<div>
    @php
        $money = fn ($value) => $value === null ? '–' : number_format($value, 2);
        $date = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->format('d M Y') : '–';
        $exportQuery = array_filter(['district' => $districtId, 'route' => $route ?: null, 'group' => $group ?: null, 'status' => $status ?: null, 'meter' => $meter ?: null, 'bucket' => $bucket !== '' ? $bucket : null, 'sort' => $sort, 'issue' => $activeIssue, 'missing' => $showMissing ? 1 : null], fn ($v) => $v !== null && $v !== '');
    @endphp

    <x-ui.page-header title="Find customers" description="Look a customer up, or list a district's customers by route, balance or a data-quality issue. Lists load a page at a time.">
        <x-slot:actions>
            <a href="{{ route('commercial.customers') }}" class="btn btn-secondary"><x-ui.icon name="chart-column" /> Back to the analysis</a>
            @if ($canExport && $districtId && ! $searching)
                <a href="{{ route('commercial.customers.export', ['report' => 'list', 'format' => 'excel', ...$exportQuery]) }}" class="btn btn-secondary"><x-ui.icon name="file-spreadsheet" /> Export this list</a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="ui-toolbar dash-row" role="search" aria-label="Customer search">
        <select wire:model.live="searchType" class="form-input" aria-label="Search by">
            @foreach ($searchTypes as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
        </select>
        <input type="search" class="form-input" wire:model.live.debounce.500ms="search" placeholder="Search all districts you may see…" aria-label="Search text" style="min-width:16rem">
        @if ($searching)<button type="button" class="btn btn-ghost btn-sm" wire:click="clearSearch">Clear search</button>@endif
        @unless ($details)<span class="ui-hint">Search by name, phone or e-mail needs the customer details permission.</span>@endunless
    </div>

    @unless ($searching)
        <div class="ui-toolbar dash-row" aria-label="List filters">
            <select wire:model.live="district" class="form-input" aria-label="District">
                <option value="">Choose a district…</option>
                @foreach ($districts as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
            </select>
            @if ($districtId)
                <select wire:model.live="route" class="form-input" aria-label="Route"><option value="">All routes</option>@foreach ($routes as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                <select wire:model.live="group" class="form-input" aria-label="Category group"><option value="">All categories</option>@foreach ($groups as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
                <select wire:model.live="status" class="form-input" aria-label="Status"><option value="">Any status</option>@foreach ($statuses as $s)<option value="{{ $s->id }}">{{ $s->meaning_confirmed ? $s->label.' ('.$s->code.')' : $s->code }}</option>@endforeach</select>
                <select wire:model.live="meter" class="form-input" aria-label="Meter status"><option value="">Any meter status</option>@foreach ($meters as $m)<option value="{{ $m->id }}">{{ $m->label }}</option>@endforeach</select>
                <select wire:model.live="bucket" class="form-input" aria-label="Arrears bucket"><option value="">Any balance</option>@foreach ($buckets as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
                <select wire:model.live="sort" class="form-input" aria-label="Order">@foreach ($sorts as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
                <select wire:model.live="issue" class="form-input" aria-label="Data-quality issue"><option value="">No issue filter</option>@foreach ($issues as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
                <label class="ui-check"><input type="checkbox" wire:model.live="missing"> Not in the latest file</label>
            @endif
        </div>
    @endunless

    @if ($error)
        <x-ui.alert tone="warning" class="dash-row">{{ $error }}</x-ui.alert>
    @endif

    @if (! $page)
        <x-ui.card><x-ui.empty-state icon="users" title="Choose a district, or search." description="A district's customers are listed by route; the search looks across every district you may see." /></x-ui.card>
    @else
        @if ($activeIssue && ! $searching)
            <p class="ui-hint dash-row">Showing accounts with: <strong>{{ $issues[$activeIssue] }}</strong>. Data as of {{ $date($asOf) }}. A list holds at most {{ number_format($issueCap) }} accounts; the count on the analysis page is always exact.</p>
        @elseif ($asOf && ! $searching)
            <p class="ui-hint dash-row">Data as of {{ $date($asOf) }}. @if ($showMissing)These accounts were in an earlier file but not the latest; they are kept, not deleted.@endif</p>
        @endif

        <x-ui.card :padded="false" class="dash-row">
            <x-ui.table label="Customers" :sticky="true">
                <x-slot:head>
                    <tr>
                        <th>Account</th>@if ($details)<th>Name</th><th>Address</th><th>Mobile</th><th>E-mail</th>@endif
                        <th>District / route</th><th>Category</th><th>Status</th><th>Meter</th><th class="num">Balance (GH¢)</th><th>Bills owed</th><th>Last bill</th><th>Last payment</th>
                    </tr>
                </x-slot:head>
                @forelse ($page['rows'] as $row)
                    <tr wire:key="cu-{{ $row['id'] }}">
                        <td class="mono"><a href="{{ route('commercial.customers.show', $row['id']) }}">{{ $row['account_no'] }}</a>@if ($row['missing'])<x-ui.badge tone="warning">Not in latest file</x-ui.badge>@endif</td>
                        @if ($details)<td>{{ $row['name'] ?? '–' }}</td><td>{{ $row['address'] ?? '–' }}</td><td class="mono">{{ $row['mobile'] ?? '–' }}@if (($row['more_phones'] ?? 0) > 0) <span class="ui-hint" title="This customer has {{ $row['more_phones'] + 1 }} mobile numbers; open the customer to see them (masked)">+{{ $row['more_phones'] }}</span>@endif</td><td>{{ $row['email'] ?? '–' }}</td>@endif
                        <td>{{ $row['district'] }} · <span class="mono">{{ $row['route'] }}</span></td><td>{{ $row['category'] }}</td><td>{{ $row['status'] }}</td><td>{{ $row['meter_status'] }}</td>
                        <td class="num">{{ $money($row['balance']) }}</td><td>{{ $row['bucket'] }}</td>
                        <td>{{ $date($row['last_bill_date']) }}@if ($row['last_bill_amount'] !== null) · {{ $money($row['last_bill_amount']) }}@endif</td>
                        <td>{{ $date($row['last_paid_date']) }}@if ($row['last_paid_amount'] !== null) · {{ $money($row['last_paid_amount']) }}@endif</td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="$details ? 13 : 9" icon="users" title="No customers match." />
                @endforelse
            </x-ui.table>
        </x-ui.card>

        @unless ($searching)
            <div class="ui-toolbar dash-row">
                <button type="button" class="btn btn-secondary btn-sm" wire:click="previous" @disabled(! $hasPrevious)>← Newer</button>
                <button type="button" class="btn btn-secondary btn-sm" wire:click='next(@json($page['next']))' @disabled($page['next'] === null)>Older →</button>
                <span class="ui-hint">{{ count($page['rows']) }} shown{{ $page['next'] ? ', more follow' : '' }}</span>
            </div>
        @endunless
    @endif
</div>
