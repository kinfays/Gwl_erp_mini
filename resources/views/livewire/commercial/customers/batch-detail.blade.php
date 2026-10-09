<div @if ($working) wire:poll.3s @endif>
    @php
        $n = fn ($v) => number_format($v);
        $tone = match ($batch->status) { 'imported' => 'success', 'blocked', 'failed' => 'danger', 'needs_match' => 'warning', 'superseded', 'voided' => 'muted', default => 'info' };
        $phases = ['queued' => 'Waiting for a worker', 'parse' => 'Reading the file', 'merge' => 'Updating customers', 'missing' => 'Finding customers no longer listed', 'rollup' => 'Building the totals', 'done' => 'Finished', 'voiding' => 'Putting the data back'];
    @endphp

    <x-ui.page-header :title="'Customer list batch #'.$batch->id" :description="($batch->district?->district_name ?? $batch->district_label_raw ?? 'District not known yet').' · '.($batch->region?->region_name ?? $batch->region_label_raw ?? 'region not known yet').' · '.$batch->period_type.' · data as of '.$batch->as_of_date->format('d M Y')">
        <x-slot:actions>
            <a href="{{ route('commercial.customers.uploads') }}" class="btn btn-secondary">Back to uploads</a>
            @if ($canVoid && $batch->isLive() && ! $voiding)<button type="button" class="btn btn-secondary" wire:click="startVoid">Void this upload</button>@endif
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('status'))<x-ui.alert tone="success" class="dash-row">{{ session('status') }}</x-ui.alert>@endif

    <div class="ui-stat-grid dash-row">
        <x-ui.stat-tile label="Status" :value="\App\Models\CommercialCustomerBatch::statusLabel($batch->status)" icon="activity" :tone="$tone" :meta="$phases[$voiding ? 'voiding' : $batch->phase] ?? $batch->phase" />
        <x-ui.stat-tile label="Rows in the file" :value="$n($batch->rows_read)" icon="users" tone="primary" :meta="$n($batch->rows_malformed).' could not be read'" />
        <x-ui.stat-tile label="New accounts" :value="$n($batch->rows_new)" icon="user-plus" tone="success" />
        <x-ui.stat-tile label="Changed" :value="$n($batch->rows_changed)" icon="pencil" tone="info" :meta="$n($batch->rows_unchanged).' unchanged: not written'" />
        <x-ui.stat-tile label="Not in this file" :value="$n($batch->rows_missing)" icon="file-minus" tone="warning" :meta="'Flagged, never deleted'" />
        <x-ui.stat-tile label="Moved in" :value="$n($batch->rows_moved)" icon="shuffle" :meta="'from another district'" />
    </div>

    @if ($working)
        <div class="dash-row" role="progressbar" aria-valuenow="{{ $batch->progressPercent() }}" aria-valuemin="0" aria-valuemax="100" aria-label="Progress" style="height:10px;background:var(--border,#ddd);border-radius:5px">
            <div style="height:100%;width:{{ max(3, $batch->progressPercent()) }}%;background:var(--primary,#2563eb);border-radius:5px;transition:width .4s"></div>
        </div>
        <p class="ui-hint">{{ $phases[$voiding ? 'voiding' : $batch->phase] ?? '' }}. This page updates by itself; you can leave it and come back.</p>
    @endif

    @if ($batch->status === 'failed')
        <x-ui.alert tone="danger" class="dash-row">{{ $batch->error_message }} @if ($canUpload)<button type="button" class="btn btn-secondary btn-sm" wire:click="retry">Run again</button>@endif</x-ui.alert>
    @elseif ($batch->error_message && $batch->status === 'imported')
        <x-ui.alert tone="warning" class="dash-row">{{ $batch->error_message }}</x-ui.alert>
    @endif

    @if ($batch->status === 'needs_match')
        <x-ui.card title="This file needs a match" description="Nothing has been read yet. Tell the system which place the file's heading means; it remembers the answer for every later upload." class="dash-row">
            @foreach ($batch->warnings ?? [] as $w)<p>{{ $w['message'] }}</p>@endforeach
            @if ($canResolve && ! $batch->region_id)
                <div class="form-row" style="align-items:flex-end;gap:8px">
                    <div class="form-field"><label class="form-label">Region "{{ $batch->region_label_raw }}" is</label>
                        <select wire:model="matchRegionId" class="form-input"><option value="">Choose…</option>@foreach ($regions as $r)<option value="{{ $r->id }}">{{ $r->region_name }}</option>@endforeach</select>
                        @error('matchRegionId')<span class="form-error">{{ $message }}</span>@enderror</div>
                    <button type="button" class="btn btn-primary" wire:click="matchRegion">Match and continue</button>
                </div>
            @elseif ($canResolve)
                <div class="form-row" style="align-items:flex-end;gap:8px">
                    <div class="form-field"><label class="form-label">District "{{ $batch->district_label_raw }}" is</label>
                        <select wire:model="matchDistrictId" class="form-input"><option value="">Choose…</option>@foreach ($districts as $d)<option value="{{ $d->id }}">{{ $d->district_name }}</option>@endforeach</select>
                        @error('matchDistrictId')<span class="form-error">{{ $message }}</span>@enderror</div>
                    <button type="button" class="btn btn-primary" wire:click="matchDistrict">Match and continue</button>
                </div>
            @else
                <p class="ui-hint">Someone with permission to resolve matches needs to do this.</p>
            @endif
        </x-ui.card>
    @endif

    @if ($confirmingVoid)
        <x-ui.card title="Void this upload" description="The customers go back exactly as they were before this file, and its totals are removed. Only the newest upload of a district can be voided." class="dash-row">
            @if ($voidBlocker)
                <x-ui.alert tone="warning">{{ $voidBlocker }}</x-ui.alert>
            @endif
            <div class="form-field"><label class="form-label">Why?</label><textarea rows="2" class="form-input" wire:model="voidReason"></textarea>@error('voidReason')<span class="form-error">{{ $message }}</span>@enderror</div>
            <div style="display:flex;gap:8px;margin-top:8px"><button type="button" class="btn btn-primary" wire:click="voidBatch" @disabled($voidBlocker)>Void upload</button><button type="button" class="btn btn-secondary" wire:click="cancelVoid">Cancel</button></div>
        </x-ui.card>
    @endif

    @if ($batch->status === 'voided')
        <x-ui.alert tone="warning" class="dash-row">Voided {{ optional($batch->voided_at)->format('d M Y H:i') }}: {{ $batch->void_reason }}. The customers were put back as they were before this file.</x-ui.alert>
    @endif

    @if (! empty($batch->errors))
        <x-ui.card title="Why this file was blocked" class="dash-row">
            <ul class="ui-list">@foreach ($batch->errors as $e)<li><strong>{{ $e['row'] }}</strong>: {{ $e['message'] }}</li>@endforeach</ul>
        </x-ui.card>
    @endif

    @if (! empty($batch->warnings) && $batch->status !== 'needs_match')
        <x-ui.card title="Warnings" description="These do not stop the import." class="dash-row">
            <ul class="ui-list">@foreach ($batch->warnings as $w)<li><strong>{{ $w['row'] }}</strong>: {{ $w['message'] }}</li>@endforeach</ul>
            @if ($batch->count_change_pct !== null)<p class="ui-hint">The district had {{ $n((int) $batch->previous_count) }} customers before; the file lists {{ $n($batch->rows_read) }} ({{ $batch->count_change_pct > 0 ? '+' : '' }}{{ $batch->count_change_pct }}%).</p>@endif
        </x-ui.card>
    @endif

    @if (! empty($batch->control_totals))
        @php $bad = collect($batch->control_totals)->where('ok', false)->count(); @endphp
        <x-ui.card title="Check against the file's own totals" :description="$bad ? $bad.' route(s) do not add up.' : 'Every route adds up to its totals row.'" :padded="false" class="dash-row">
            <x-ui.table label="Route reconciliation" :sticky="true">
                <x-slot:head><tr><th>Route</th><th class="num">Customers read</th><th class="num">Totals row says</th><th class="num">Balance read (GH¢)</th><th class="num">Totals row says (GH¢)</th><th></th></tr></x-slot:head>
                @foreach ($batch->control_totals as $row)
                    <tr wire:key="ct-{{ $loop->index }}">
                        <td class="mono">{{ $row['route'] }}</td><td class="num">{{ $n($row['read']) }}</td><td class="num">{{ $row['expected'] === null ? '–' : $n($row['expected']) }}</td>
                        <td class="num">{{ number_format($row['read_balance'] / 100, 2) }}</td><td class="num">{{ $row['expected_balance'] === null ? '–' : number_format($row['expected_balance'] / 100, 2) }}</td>
                        <td>@if ($row['ok'])<x-ui.badge tone="success">Agrees</x-ui.badge>@else<x-ui.badge tone="danger">Does not add up</x-ui.badge>@endif</td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif

    <p class="ui-hint dash-row">Uploaded {{ $batch->created_at->format('d M Y H:i') }}@if ($batch->importer) by {{ $batch->importer->full_name }}@endif. The file's personal data is deleted as soon as the import finishes or is blocked.</p>
</div>
