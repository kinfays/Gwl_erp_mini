<div>
    <x-ui.page-header title="Route master" description="Every route the reports and the customer list name, with its district. The same code printed under two districts is one route and an alias; which one is the route is decided by the setting below, or by a Commercial Manager.">
        <x-slot:actions>
            @if ($pendingCount > 0)
                <button type="button" class="btn btn-primary" wire:click="confirmAllPending" wire:confirm="Confirm all {{ number_format($pendingCount) }} pending route(s)? It only marks that somebody looked at them; no route changes district.">Confirm all pending ({{ number_format($pendingCount) }})</button>
            @endif
            <button type="button" class="btn btn-secondary" wire:click="rebuild" wire:confirm="Settle every shared code again under the current setting? Routes a manager pinned or marked by hand stay as they are.">Settle shared codes again</button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="stat-grid dash-row">
        <x-ui.stat-tile label="Codes under several districts" :value="number_format(count($conflicts['conflicts']))" icon="triangle-alert" tone="warning" meta="Each is one route and an alias" />
        <x-ui.stat-tile label="Unassigned routes (0000)" :value="number_format($conflicts['unassigned'])" icon="circle-slash" tone="danger" meta="Customers with no route" />
        <x-ui.stat-tile label="The district of a route comes from" :value="$authority === 'billing' ? 'Billing / reading' : 'Customer list'" icon="settings" tone="info" meta="Change it under Settings" />
    </div>

    <div class="ui-toolbar dash-row">
        <select wire:model.live="filter" class="form-input" aria-label="Show">
            @foreach ($filters as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
        </select>
        @if ($regionOptions->count() > 1)
            <select wire:model.live="region" class="form-input" aria-label="Region">
                <option value="">All regions</option>
                @foreach ($regionOptions as $r)<option value="{{ $r->id }}">{{ $r->region_name }}</option>@endforeach
            </select>
        @endif
        <input type="search" wire:model.live.debounce.300ms="search" class="form-input" placeholder="Route code" aria-label="Route code">
    </div>

    @if ($editingRoute)
        <x-ui.card :title="'Route '.($editingRoute->code ?? $editingRoute->name).' ('.($editingRoute->district?->district_name ?? 'no district').')'" description="Pin the route to the district its customers are reported under, or mark it as the alias of another route with the same code." class="dash-row">
            <div class="form-row" style="gap:16px;align-items:flex-end;flex-wrap:wrap">
                <div class="form-field">
                    <label class="form-label" for="pin-district">Pin to district</label>
                    <select id="pin-district" class="form-input" wire:model="pinDistrictId">
                        <option value="">Choose a district…</option>
                        @foreach ($districts as $d)<option value="{{ $d->id }}">{{ $d->district_name }}</option>@endforeach
                    </select>
                    @error('pinDistrictId')<span class="form-error">{{ $message }}</span>@enderror
                </div>
                <button type="button" class="btn btn-primary" wire:click="pin">Pin the route</button>

                <div class="form-field">
                    <label class="form-label" for="alias-of">Alias of</label>
                    <select id="alias-of" class="form-input" wire:model="aliasOfId">
                        <option value="">Choose a route…</option>
                        @foreach ($sameCode as $other)<option value="{{ $other->id }}">{{ $other->code }} · {{ $other->district?->district_name }}</option>@endforeach
                    </select>
                    @error('aliasOfId')<span class="form-error">{{ $message }}</span>@enderror
                    @if ($sameCode->isEmpty())<span class="form-hint">No other route has this code.</span>@endif
                </div>
                <button type="button" class="btn btn-secondary" wire:click="markAlias" @disabled($sameCode->isEmpty())>Mark as alias</button>
                <button type="button" class="btn btn-ghost" wire:click="cancel">Cancel</button>
            </div>
        </x-ui.card>
    @endif

    <x-ui.card :padded="false" class="dash-row">
        <x-ui.table label="Routes" :sticky="true">
            <x-slot:head><tr><th>Code</th><th>District</th><th>Region</th><th>First seen in</th><th>Is</th><th class="num">Customers</th><th></th></tr></x-slot:head>
            @forelse ($routes as $route)
                <tr wire:key="route-{{ $route->id }}">
                    <td class="mono">{{ $route->code ?? $route->name }}@if ($route->code === \App\Models\CommercialRoute::UNASSIGNED_CODE) <x-ui.badge tone="danger">unassigned</x-ui.badge>@endif</td>
                    <td>{{ $route->district?->district_name }}</td>
                    <td>{{ $route->region?->region_name }}</td>
                    <td>{{ ['reading' => 'Meter reading', 'billing' => 'Billing', 'customer_list' => 'Customer list', 'manual' => 'Entered by hand'][$route->source] ?? $route->source }}@if ($route->pinned) <x-ui.badge tone="primary">set by a manager</x-ui.badge>@endif @if ($route->is_pending) <x-ui.badge tone="warning">pending</x-ui.badge>@endif</td>
                    <td>
                        @if ($route->isAlias())
                            Alias of {{ $route->canonical?->district?->district_name ?? 'another route' }}
                        @else
                            <x-ui.badge tone="success">Route</x-ui.badge>
                        @endif
                    </td>
                    <td class="num">{{ number_format($customers[$route->id] ?? 0) }}</td>
                    <td style="white-space:nowrap">
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="startPin({{ $route->id }})">Pin / alias</button>
                        @if ($route->pinned)<button type="button" class="btn btn-ghost btn-sm" wire:click="release({{ $route->id }})">Let the setting decide</button>@endif
                        @if ($route->is_pending)<button type="button" class="btn btn-ghost btn-sm" wire:click="confirm({{ $route->id }})">Confirm</button>@endif
                    </td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="7" icon="map" title="No routes match." />
            @endforelse
        </x-ui.table>
        <div style="padding:8px 14px">{{ $routes->links() }}</div>
    </x-ui.card>
</div>
