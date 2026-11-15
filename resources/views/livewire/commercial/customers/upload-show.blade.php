<div @if ($working) wire:poll.3s @endif>
    @php
        $n = fn ($v) => number_format($v);
        $tone = fn (string $s) => match ($s) {
            'imported' => 'success', 'blocked', 'failed' => 'danger', 'needs_match', 'awaiting_review', 'partial' => 'warning', 'superseded', 'voided' => 'muted', default => 'info',
        };
        $phases = ['queued' => 'Waiting for a worker', 'discover' => 'Finding the districts in the file', 'review' => 'Waiting for you to check the districts', 'stage' => 'Reading the file', 'children' => 'Updating the districts', 'done' => 'Finished'];
        $batchPhases = ['queued' => 'Waiting', 'parse' => 'Waiting to be read', 'merge' => 'Updating customers', 'missing' => 'Finding customers no longer listed', 'rollup' => 'Building the totals', 'done' => 'Finished', 'voiding' => 'Putting the data back'];
        $district = fn ($e) => $e['name'] ?? $e['label'] ?? '–';
    @endphp

    <x-ui.page-header :title="'Customer list upload #'.$upload->id" :description="$upload->source_filename.' · '.($upload->region?->region_name ?? $upload->region_label_raw ?? 'region not known yet').' · '.$upload->period_type.' · data as of '.$upload->as_of_date->format('d M Y')">
        <x-slot:actions>
            <a href="{{ route('commercial.customers.uploads') }}" class="btn btn-secondary">Back to uploads</a>
            @if ($canVoid && $undoable > 0 && ! $working)<button type="button" class="btn btn-secondary" wire:click="startVoid">Undo this upload</button>@endif
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('status'))<x-ui.alert tone="success" class="dash-row">{{ session('status') }}</x-ui.alert>@endif
    @error('selected')<x-ui.alert tone="danger" class="dash-row">{{ $message }}</x-ui.alert>@enderror

    <div class="ui-stat-grid dash-row">
        <x-ui.stat-tile label="Status" :value="\App\Models\CommercialCustomerUpload::statusLabel($upload->status)" icon="activity" :tone="$tone($upload->status)" :meta="$phases[$upload->phase] ?? $upload->phase" />
        <x-ui.stat-tile label="Districts in the file" :value="$n($upload->districts_found)" icon="map-pin" tone="primary" :meta="$n($upload->districts_selected).' to be loaded'" />
        <x-ui.stat-tile label="Routes" :value="$n($upload->routes_found)" icon="route" />
        <x-ui.stat-tile label="Customers in the file" :value="$n($upload->rows_found)" icon="users" tone="info" :meta="$upload->rows_skipped > 0 ? $n($upload->rows_skipped).' not loaded' : null" />
    </div>

    @if ($working)
        <div class="dash-row" role="progressbar" aria-valuenow="{{ $progress }}" aria-valuemin="0" aria-valuemax="100" aria-label="Progress" style="height:10px;background:var(--border,#ddd);border-radius:5px">
            <div style="height:100%;width:{{ max(3, $progress) }}%;background:var(--primary,#2563eb);border-radius:5px;transition:width .4s"></div>
        </div>
        <p class="ui-hint">{{ $phases[$upload->phase] ?? '' }}. This page updates by itself; you can leave it and come back.</p>
    @endif

    @if ($upload->status === 'failed' || $batches->contains(fn ($b) => $b->status === 'failed'))
        <x-ui.alert tone="danger" class="dash-row">{{ $upload->error_message ?: 'A district stopped before it was finished; the others were loaded.' }} @if ($canUpload)<button type="button" class="btn btn-secondary btn-sm" wire:click="retry">Run again</button>@endif</x-ui.alert>
    @endif

    @foreach ($upload->errors ?? [] as $e)<x-ui.alert tone="danger" class="dash-row">{{ $e['message'] }}</x-ui.alert>@endforeach
    @foreach ($upload->warnings ?? [] as $w)
        @if ($upload->status !== 'needs_match')<x-ui.alert tone="warning" class="dash-row">{{ $w['message'] }}</x-ui.alert>@endif
    @endforeach
    @if ($skippedNotYours > 0 && empty($upload->warnings))
        <x-ui.alert tone="warning" class="dash-row">{{ $skippedNotYours }} other {{ $skippedNotYours === 1 ? 'district' : 'districts' }} skipped (not yours); nothing stored.</x-ui.alert>
    @endif

    {{-- ============================================ the check before anything is loaded --}}
    @if ($upload->status === 'awaiting_review')
        <x-ui.card title="This file will update these districts" description="Nothing has been loaded yet. Untick a district you do not want to update: its customers are not read and nothing of it changes." class="dash-row">
            <form wire:submit="confirmDistricts">
                <ul style="list-style:none;padding:0;margin:0 0 12px">
                    @foreach ($entries as $e)
                        @if (($e['state'] ?? '') === 'load')
                            <li style="padding:6px 0"><label style="display:flex;gap:10px;align-items:center">
                                <input type="checkbox" wire:model="selected" value="{{ $e['district_id'] }}" @disabled(! $canUpload)>
                                <strong>{{ $district($e) }}</strong>
                                <span class="ui-hint">{{ $n($e['rows']) }} customers in {{ $n($e['routes']) }} {{ $e['routes'] === 1 ? 'route' : 'routes' }}</span>
                            </label></li>
                        @elseif (($e['state'] ?? '') === 'blocked')
                            <li style="padding:6px 0"><strong>{{ $district($e) }}</strong> <x-ui.badge tone="danger">will not be loaded</x-ui.badge> <span class="ui-hint">{{ $e['note'] }}</span></li>
                        @endif
                    @endforeach
                </ul>
                @if ($canUpload)
                    <button type="submit" class="btn btn-primary">Update the ticked districts</button>
                    <button type="button" class="btn btn-secondary" wire:click="cancelUpload" wire:confirm="Cancel this upload? Nothing has been loaded and the file will be deleted.">Cancel this upload</button>
                @endif
            </form>
        </x-ui.card>
    @endif

    {{-- ============================================ names nobody could place --}}
    @if ($upload->status === 'needs_match')
        <x-ui.card title="This file needs a match" description="Nothing has been read yet. Tell the system which place each name in the file means; it remembers the answers for every later upload." class="dash-row">
            @foreach ($upload->warnings ?? [] as $w)<p>{{ $w['message'] }}</p>@endforeach
            @if ($canResolve && ! $upload->region_id)
                <div class="form-row" style="align-items:flex-end;gap:8px">
                    <div class="form-field"><label class="form-label">Region "{{ $upload->region_label_raw }}" is</label>
                        <select wire:model="matchRegionId" class="form-input"><option value="">Choose…</option>@foreach ($regions as $r)<option value="{{ $r->id }}">{{ $r->region_name }}</option>@endforeach</select>
                        @error('matchRegionId')<span class="form-error">{{ $message }}</span>@enderror</div>
                    <button type="button" class="btn btn-primary" wire:click="matchRegion">Match and continue</button>
                </div>
            @elseif ($canResolve)
                @foreach ($upload->unrecognised ?? [] as $i => $u)
                    <div class="form-row" style="align-items:flex-end;gap:8px;margin-bottom:8px" wire:key="un-{{ $i }}">
                        <div class="form-field"><label class="form-label">District "{{ $u['label'] }}" ({{ $n($u['rows']) }} customers) is</label>
                            <select wire:model="matches.{{ $i }}" class="form-input"><option value="">Choose…</option>@foreach ($districts as $d)<option value="{{ $d->id }}">{{ $d->district_name }}</option>@endforeach</select>
                            @error('matches.'.$i)<span class="form-error">{{ $message }}</span>@enderror</div>
                    </div>
                @endforeach
                <button type="button" class="btn btn-primary" wire:click="matchDistricts">Match and continue</button>
                @if ($canUpload)<button type="button" class="btn btn-secondary" wire:click="cancelUpload" wire:confirm="Cancel this upload? Nothing has been loaded and the file will be deleted.">Cancel this upload</button>@endif
            @else
                <p class="ui-hint">Someone with permission to resolve matches needs to do this.</p>
            @endif
        </x-ui.card>
    @endif

    @if ($confirmingVoid)
        <x-ui.card title="Undo this upload" description="Every district of this upload that is still the newest of its district is put back exactly as it was before. A district that has had a newer file since cannot be undone and is left as it is." class="dash-row">
            <div class="form-field"><label class="form-label" for="uv-reason">Why?</label>
                <input id="uv-reason" type="text" wire:model="voidReason" maxlength="500" class="form-input">
                @error('voidReason')<span class="form-error">{{ $message }}</span>@enderror</div>
            <p class="ui-hint">{{ $undoable }} of {{ $batches->count() }} {{ $batches->count() === 1 ? 'district' : 'districts' }} can be undone.</p>
            <button type="button" class="btn btn-danger" wire:click="voidUpload">Undo it</button>
            <button type="button" class="btn btn-secondary" wire:click="cancelVoid">Keep it</button>
        </x-ui.card>
    @endif

    {{-- ============================================ every district's batch --}}
    <x-ui.card title="Districts" :description="'One batch per district: each has its own progress, checks and undo.'" :padded="false" class="dash-row">
        <x-ui.table label="Districts of this upload" :sticky="false">
            <x-slot:head><tr><th>District</th><th>Batch</th><th>Status</th><th class="num">Customers</th><th class="num">New</th><th class="num">Changed</th><th class="num">Not in file</th><th></th></tr></x-slot:head>
            @forelse ($batches as $b)
                <tr wire:key="ub-{{ $b->id }}">
                    <td><strong>{{ $b->district?->district_name ?? $b->district_label_raw ?? '–' }}</strong></td>
                    <td>#{{ $b->id }}</td>
                    <td>
                        <x-ui.badge :tone="$tone($b->status)">{{ \App\Models\CommercialCustomerBatch::statusLabel($b->status) }}</x-ui.badge>
                        <span class="ui-hint">{{ $batchPhases[$b->phase] ?? $b->phase }}</span>
                        @if ($b->status === 'blocked' && ! empty($b->errors[0]['message']))<div class="ui-hint">{{ $b->errors[0]['message'] }}</div>@endif
                        @if ($b->count_change_pct !== null && abs((float) $b->count_change_pct) > (float) config('gwl.commercial_customer_count_change_warn_pct', 20))<div class="ui-hint">Customer count moved by {{ $b->count_change_pct > 0 ? '+' : '' }}{{ $b->count_change_pct }}% against the district's previous upload.</div>@endif
                    </td>
                    <td class="num">{{ $n($b->rows_read) }}</td><td class="num">{{ $n($b->rows_new) }}</td><td class="num">{{ $n($b->rows_changed) }}</td><td class="num">{{ $n($b->rows_missing) }}</td>
                    <td><a href="{{ route('commercial.customers.batch', $b) }}" class="btn btn-ghost btn-sm">Open</a></td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="8" icon="map-pin" title="No district has been set up for this file yet." />
            @endforelse
        </x-ui.table>
    </x-ui.card>

    {{-- the routes that disagree with their own totals, in every district together --}}
    @if ($flagged !== [])
        <x-ui.card title="Routes that disagree with their own totals" description="Flagged, and the district still loaded (a district is stopped only when too many routes fail). Check these routes in the file." :padded="false" class="dash-row">
            <x-ui.table label="Flagged routes" :sticky="false">
                <x-slot:head><tr><th>District</th><th>Route</th><th class="num">Customers listed</th><th class="num">Totals row says</th><th class="num">Difference</th><th class="num">Balance difference (GH¢)</th></tr></x-slot:head>
                @foreach (array_slice($flagged, 0, 100) as $f)
                    <tr wire:key="fl-{{ $f['batch_id'] }}-{{ $loop->index }}">
                        <td>{{ $f['district'] }}</td><td>{{ $f['route'] }}</td><td class="num">{{ $n($f['read']) }}</td><td class="num">{{ $f['expected'] === null ? '–' : $n($f['expected']) }}</td>
                        <td class="num">{{ $f['count_difference'] === null ? '–' : ($f['count_difference'] > 0 ? '+' : '').$f['count_difference'] }}</td>
                        <td class="num">{{ $f['balance_difference'] === null ? '–' : number_format($f['balance_difference'], 2) }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif
</div>
