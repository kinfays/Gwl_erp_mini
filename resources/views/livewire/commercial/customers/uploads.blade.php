<div @if ($working) wire:poll.4s @endif>
    @php
        $tone = fn (string $s) => match ($s) {
            'imported' => 'success', 'superseded' => 'muted', 'voided' => 'muted', 'blocked', 'failed' => 'danger', 'needs_match', 'awaiting_review', 'partial' => 'warning', default => 'info',
        };
    @endphp

    <x-ui.page-header title="Customer list uploads" description="Upload the customer list report (rptCustomerDetails) for one district, several or every district of a region, weekly or monthly. The file says which districts it holds. Big files are read in the background and a progress bar shows where they are.">
        <x-slot:actions>
            <a href="{{ route('commercial.customers') }}" class="btn btn-secondary"><x-ui.icon name="chart-column" /> Customer analysis</a>
            @if ($canLookups)<a href="{{ route('commercial.customers.lookups') }}" class="btn btn-secondary"><x-ui.icon name="settings" /> Categories, statuses & cadence</a>@endif
        </x-slot:actions>
    </x-ui.page-header>

    @include('livewire.commercial.partials.uploads-switch', ['current' => 'customers'])

    @if (session('status'))<x-ui.alert tone="success" class="dash-row">{{ session('status') }}</x-ui.alert>@endif
    @if (session('error'))<x-ui.alert tone="danger" class="dash-row">{{ session('error') }}</x-ui.alert>@endif

    @if ($overdue !== [])
        <x-ui.alert tone="warning" class="dash-row">
            <strong>{{ count($overdue) }} {{ \Illuminate\Support\Str::plural('district', count($overdue)) }} overdue:</strong>
            @foreach (array_slice($overdue, 0, 8) as $late){{ $late['district'] }} ({{ $late['days'] }} days, {{ $late['cadence'] }}){{ $loop->last ? '' : ', ' }}@endforeach
            @if (count($overdue) > 8) and {{ count($overdue) - 8 }} more @endif
        </x-ui.alert>
    @endif

    @if ($canUpload)
        <x-ui.card title="Upload a customer list" description="One district, several or every district of the region in one file. Each route is checked against its own totals before anything is changed, and only the districts in the file are touched." class="dash-row">
            <form method="POST" action="{{ route('commercial.customers.upload') }}" enctype="multipart/form-data" class="form-row" style="align-items:flex-end;gap:12px;flex-wrap:wrap">
                @csrf
                <div class="form-field">
                    <label class="form-label" for="cu-file">Excel file (.xlsx, up to {{ $maxMb }} MB)</label>
                    <input id="cu-file" type="file" name="file" accept=".xlsx" required class="form-input">
                    @error('file')<span class="form-error">{{ $message }}</span>@enderror
                </div>
                <div class="form-field">
                    <label class="form-label" for="cu-asof">Data as of</label>
                    <input id="cu-asof" type="date" name="as_of_date" value="{{ old('as_of_date', now()->toDateString()) }}" max="{{ now()->addDay()->toDateString() }}" required class="form-input">
                    @error('as_of_date')<span class="form-error">{{ $message }}</span>@enderror
                </div>
                <div class="form-field">
                    <label class="form-label" for="cu-period">Upload type</label>
                    <select id="cu-period" name="period_type" class="form-input"><option value="monthly" @selected(old('period_type') === 'monthly')>Monthly</option><option value="weekly" @selected(old('period_type') === 'weekly')>Weekly</option><option value="daily" @selected(old('period_type') === 'daily')>Daily</option></select>
                </div>
                <div class="form-field" style="flex:1;min-width:14rem">
                    <label class="form-label" for="cu-notes">Notes (optional)</label>
                    <input id="cu-notes" type="text" name="notes" maxlength="2000" value="{{ old('notes') }}" class="form-input">
                </div>
                <div class="form-field" style="flex-basis:100%"><label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="review" value="1" @checked(old('review', '1') === '1')> Show me the districts in this file before anything is loaded (recommended)</label></div>
                <div class="form-field"><button type="submit" class="btn btn-primary">Upload</button></div>
            </form>
            <p class="ui-hint">The report has no date of its own, so say what day the data is from. A district whose data is newer than the file is skipped, and the others are still loaded. The same file twice is never done twice.</p>
        </x-ui.card>
    @endif

    <div class="ui-toolbar dash-row">
        <select wire:model.live="status" class="form-input" aria-label="Status">
            <option value="">Any status</option>
            @foreach ($statuses as $s)<option value="{{ $s }}">{{ \App\Models\CommercialCustomerUpload::statusLabel($s) }}</option>@endforeach
        </select>
    </div>

    <x-ui.card :padded="false" class="dash-row">
        <x-ui.table label="Customer list uploads" :sticky="true">
            <x-slot:head><tr><th>Upload</th><th>Districts</th><th>Data as of</th><th>Status</th><th class="num">Customers</th><th class="num">New</th><th class="num">Changed</th><th class="num">Not in file</th><th>Uploaded</th><th></th></tr></x-slot:head>
            @forelse ($uploads as $u)
                @php
                    $kids = $children[$u->id] ?? collect();
                    $kidsLoaded = $kids->filter(fn ($b) => $b->isLive())->count();
                @endphp
                <tr wire:key="up-{{ $u->id }}">
                    <td>#{{ $u->id }}<span class="ui-hint"> {{ $u->period_type }}</span><div class="ui-hint" style="max-width:16rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="{{ $u->source_filename }}">{{ $u->source_filename }}</div></td>
                    <td>
                        @foreach ($kids->take(4) as $k)
                            <a href="{{ route('commercial.customers.batch', $k) }}" class="ui-hint" style="margin-right:6px;white-space:nowrap">{{ $k->district?->district_name ?? $k->district_label_raw ?? 'District not known yet' }} #{{ $k->id }}</a>
                        @endforeach
                        @if ($kids->count() > 4)<span class="ui-hint">and {{ $kids->count() - 4 }} more</span>@endif
                        @if ($kids->count() > 1)<button type="button" class="btn btn-ghost btn-sm" wire:click="toggle({{ $u->id }})" aria-expanded="{{ ($open[$u->id] ?? false) ? 'true' : 'false' }}">{{ ($open[$u->id] ?? false) ? 'Hide' : 'Show' }} all {{ $kids->count() }}</button>@endif
                    </td>
                    <td>{{ $u->as_of_date->format('d M Y') }}</td>
                    <td>
                        <x-ui.badge :tone="$tone($u->status)">{{ \App\Models\CommercialCustomerUpload::statusLabel($u->status) }}</x-ui.badge>
                        @if ($kids->count() > 1 && in_array($u->status, ['processing', 'imported', 'partial', 'failed', 'blocked'], true))<div class="ui-hint">{{ $kidsLoaded }} of {{ $kids->count() }} districts loaded</div>@endif
                        @if ($u->isWorking())
                            @php $pct = $u->progressPercent($kids); @endphp
                            <div class="ui-meter" role="progressbar" aria-valuenow="{{ $pct }}" aria-valuemin="0" aria-valuemax="100" style="height:6px;background:var(--border,#ddd);border-radius:3px;margin-top:4px;min-width:90px"><div style="height:100%;width:{{ max(3, $pct) }}%;background:var(--primary,#2563eb);border-radius:3px"></div></div>
                        @endif
                    </td>
                    <td class="num">{{ number_format($kids->sum('rows_read') ?: $u->rows_found) }}</td><td class="num">{{ number_format($kids->sum('rows_new')) }}</td><td class="num">{{ number_format($kids->sum('rows_changed')) }}</td><td class="num">{{ number_format($kids->sum('rows_missing')) }}</td>
                    <td>{{ $u->created_at->format('d M Y H:i') }}@if ($u->importer)<span class="ui-hint"> {{ $u->importer->full_name }}</span>@endif</td>
                    <td><a href="{{ route('commercial.customers.file', $u) }}" class="btn btn-ghost btn-sm">Open</a></td>
                </tr>
                @if ($open[$u->id] ?? false)
                    @foreach ($kids as $k)
                        <tr wire:key="upk-{{ $k->id }}" style="background:var(--surface-2,#f7f7f8)">
                            <td></td>
                            <td><a href="{{ route('commercial.customers.batch', $k) }}">{{ $k->district?->district_name ?? $k->district_label_raw ?? '–' }}</a> <span class="ui-hint">#{{ $k->id }}</span></td>
                            <td></td>
                            <td><x-ui.badge :tone="$tone($k->status)">{{ \App\Models\CommercialCustomerBatch::statusLabel($k->status) }}</x-ui.badge></td>
                            <td class="num">{{ number_format($k->rows_read) }}</td><td class="num">{{ number_format($k->rows_new) }}</td><td class="num">{{ number_format($k->rows_changed) }}</td><td class="num">{{ number_format($k->rows_missing) }}</td>
                            <td></td>
                            <td><a href="{{ route('commercial.customers.batch', $k) }}" class="btn btn-ghost btn-sm">Open</a></td>
                        </tr>
                    @endforeach
                @endif
            @empty
                <x-ui.empty-row :colspan="10" icon="file-spreadsheet" title="No customer list has been uploaded yet." />
            @endforelse
        </x-ui.table>
        <div class="dash-row">{{ $uploads->links() }}</div>
    </x-ui.card>
</div>
