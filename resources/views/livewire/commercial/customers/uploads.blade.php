<div @if ($working) wire:poll.4s @endif>
    @php
        $tone = fn (string $s) => match ($s) {
            'imported' => 'success', 'superseded' => 'muted', 'voided' => 'muted', 'blocked', 'failed' => 'danger', 'needs_match' => 'warning', default => 'info',
        };
    @endphp

    <x-ui.page-header title="Customer list uploads" description="Upload the customer list report (rptCustomerDetails) for one district, weekly or monthly. Big files are read in the background and a progress bar shows where they are.">
        <x-slot:actions>
            <a href="{{ route('commercial.customers') }}" class="btn btn-secondary"><x-ui.icon name="chart-column" /> Customer analysis</a>
            @if ($canLookups)<a href="{{ route('commercial.customers.lookups') }}" class="btn btn-secondary"><x-ui.icon name="settings" /> Categories, statuses & cadence</a>@endif
        </x-slot:actions>
    </x-ui.page-header>

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
        <x-ui.card title="Upload a customer list" description="One district per file. The file is checked against its own route totals before anything is changed." class="dash-row">
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
                    <select id="cu-period" name="period_type" class="form-input"><option value="monthly" @selected(old('period_type') === 'monthly')>Monthly</option><option value="weekly" @selected(old('period_type') === 'weekly')>Weekly</option></select>
                </div>
                <div class="form-field" style="flex:1;min-width:14rem">
                    <label class="form-label" for="cu-notes">Notes (optional)</label>
                    <input id="cu-notes" type="text" name="notes" maxlength="2000" value="{{ old('notes') }}" class="form-input">
                </div>
                <div class="form-field"><button type="submit" class="btn btn-primary">Upload</button></div>
            </form>
            <p class="ui-hint">The report has no date of its own, so say what day the data is from. A file older than the data already loaded for a district is refused. The same file twice is never done twice.</p>
        </x-ui.card>
    @endif

    <div class="ui-toolbar dash-row">
        <select wire:model.live="status" class="form-input" aria-label="Status">
            <option value="">Any status</option>
            @foreach ($statuses as $s)<option value="{{ $s }}">{{ \App\Models\CommercialCustomerBatch::statusLabel($s) }}</option>@endforeach
        </select>
    </div>

    <x-ui.card :padded="false" class="dash-row">
        <x-ui.table label="Customer list batches" :sticky="true">
            <x-slot:head><tr><th>Batch</th><th>District</th><th>Data as of</th><th>Status</th><th class="num">Rows</th><th class="num">New</th><th class="num">Changed</th><th class="num">Not in file</th><th>Uploaded</th><th></th></tr></x-slot:head>
            @forelse ($batches as $b)
                <tr wire:key="cb-{{ $b->id }}">
                    <td>#{{ $b->id }}<span class="ui-hint"> {{ $b->period_type }}</span></td>
                    <td>{{ $b->district?->district_name ?? $b->district_label_raw ?? '–' }}</td>
                    <td>{{ $b->as_of_date->format('d M Y') }}</td>
                    <td>
                        <x-ui.badge :tone="$tone($b->status)">{{ \App\Models\CommercialCustomerBatch::statusLabel($b->status) }}</x-ui.badge>
                        @if ($b->isWorking())
                            <div class="ui-meter" role="progressbar" aria-valuenow="{{ $b->progressPercent() }}" aria-valuemin="0" aria-valuemax="100" style="height:6px;background:var(--border,#ddd);border-radius:3px;margin-top:4px;min-width:90px"><div style="height:100%;width:{{ $b->progressPercent() }}%;background:var(--primary,#2563eb);border-radius:3px"></div></div>
                        @endif
                    </td>
                    <td class="num">{{ number_format($b->rows_read) }}</td><td class="num">{{ number_format($b->rows_new) }}</td><td class="num">{{ number_format($b->rows_changed) }}</td><td class="num">{{ number_format($b->rows_missing) }}</td>
                    <td>{{ $b->created_at->format('d M Y H:i') }}@if ($b->importer)<span class="ui-hint"> {{ $b->importer->full_name }}</span>@endif</td>
                    <td><a href="{{ route('commercial.customers.batch', $b) }}" class="btn btn-ghost btn-sm">Open</a></td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="10" icon="file-spreadsheet" title="No customer list has been uploaded yet." />
            @endforelse
        </x-ui.table>
        <div class="dash-row">{{ $batches->links() }}</div>
    </x-ui.card>
</div>
