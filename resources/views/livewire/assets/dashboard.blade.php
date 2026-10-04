<div>
    @assets
        @vite('resources/js/charts.js')
    @endassets

    <x-ui.page-header title="ICT Asset Dashboard" description="Live visibility across inventory, maintenance, and agent health.">
        <x-slot:actions>
            <a href="{{ route('assets.assets') }}" class="btn btn-primary">
                <x-ui.icon name="laptop" />
                Open Assets
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    @php
        $cardMeta = [
            'computers' => ['Computers', 'monitor', 'primary'],
            'laptops' => ['Laptops', 'laptop', 'info'],
            'printers' => ['Printers', 'printer', 'muted'],
            'phones' => ['Phones', 'smartphone', 'lagoon'],
            'servers' => ['Servers', 'server', 'warning'],
            'network' => ['Network Devices', 'network', 'success'],
        ];
        // Fixed categorical slots per device family, so a colour never moves with rank.
        $allocationColours = [
            'Computers' => 'series-1',
            'Phones' => 'series-2',
            'Network Devices' => 'series-3',
            'Printers' => 'series-4',
        ];
        $allocationTotal = collect($allocation)->sum('total');
    @endphp

    <div class="ui-stat-grid dash-row">
        @foreach ($cardMeta as $key => [$label, $icon, $tone])
            @php($card = $cards[$key])
            <x-ui.stat-tile :label="$label" :value="$card['total']" :icon="$icon" :tone="$tone">
                @if (count($card['badges']))
                    <span class="ui-tags stat-breakdown">
                        @foreach ($card['badges'] as $type => $count)
                            <x-ui.badge>{{ $type }}: {{ $count }}</x-ui.badge>
                        @endforeach
                    </span>
                @endif
            </x-ui.stat-tile>
        @endforeach
    </div>

    <div class="ui-grid ui-grid-main dash-row">
        <x-ui.card title="District Breakdown" description="Assets per district; Attention means an open maintenance ticket or issue report." :padded="false">
            <x-ui.table label="Assets by district" :sticky="false" pin-first>
                <x-slot:head>
                    <tr>
                        <th>District</th>
                        <th class="num">Total Assets</th>
                        <th class="num">Computers</th>
                        <th class="num">Laptops</th>
                        <th class="num">Printers</th>
                        <th class="num">Phones</th>
                        <th class="num">Network</th>
                        <th>Status</th>
                    </tr>
                </x-slot:head>

                @forelse ($districtBreakdown as $row)
                    <tr>
                        <td class="nowrap">{{ $row['district_name'] }}</td>
                        <td class="num"><strong>{{ $row['total'] }}</strong></td>
                        <td class="num">{{ $row['computers'] }}</td>
                        <td class="num">{{ $row['laptops'] }}</td>
                        <td class="num">{{ $row['printers'] }}</td>
                        <td class="num">{{ $row['phones'] }}</td>
                        <td class="num">{{ $row['network'] }}</td>
                        <td>
                            <x-ui.status-pill :tone="$row['status'] === 'OPTIMAL' ? 'success' : 'warning'" :label="\Illuminate\Support\Str::title(strtolower($row['status']))" />
                        </td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="8" icon="map-pin" title="No district totals available." />
                @endforelse
            </x-ui.table>
        </x-ui.card>

        <x-ui.card title="Device Allocation" description="Share of computers, phones, network devices and printers">
            <x-ui.chart type="doughnut" label="Device allocation by category" center center-caption="devices"
                :color-map="$allocationColours"
                :labels="collect($allocation)->pluck('label')->all()"
                :series="[['label' => 'Devices', 'data' => collect($allocation)->pluck('total')->all()]]"
                height="240" empty-text="No devices recorded yet." />
        </x-ui.card>
    </div>

    <div class="dash-row">
        <x-ui.card title="Unassigned Assets" description="Devices with no employee recorded as the holder.">
            <x-ui.stat-tile label="Unassigned" :value="$unassigned['total']" icon="laptop" tone="warning">
                <span class="ui-tags stat-breakdown">
                    @foreach (['asset' => 'Assets', 'phone' => 'Phones', 'network' => 'Network'] as $category => $name)
                        @if ($unassigned['by_category'][$category] > 0)
                            <a href="{{ route(\App\Services\Assets\AssetDashboardService::CATEGORY_ROUTES[$category], $unassigned['params']) }}"><x-ui.badge>{{ $name }}: {{ $unassigned['by_category'][$category] }}</x-ui.badge></a>
                        @else
                            <x-ui.badge>{{ $name }}: 0</x-ui.badge>
                        @endif
                    @endforeach
                </span>
            </x-ui.stat-tile>
        </x-ui.card>
    </div>

    <x-ui.card title="Recently Updated Assets" :padded="false">
        <x-slot:actions>
            <a href="{{ route('assets.assets') }}" class="btn btn-ghost btn-sm">
                View all
                <x-ui.icon name="arrow-right" class="icon-sm" />
            </a>
        </x-slot:actions>

        <x-ui.table label="Recently updated assets" :sticky="false">
            <x-slot:head>
                <tr>
                    <th>Asset</th>
                    <th>District</th>
                    <th>Assigned To</th>
                    <th>Status</th>
                    <th>Updated</th>
                </tr>
            </x-slot:head>

            @forelse ($recentAssets as $asset)
                <tr>
                    <td>
                        <span class="ui-cell-stack">
                            <span class="ui-person-name">{{ $asset->asset_name }}</span>
                            <span class="ui-person-sub mono">{{ $asset->serial_number ?: 'No serial' }}</span>
                        </span>
                    </td>
                    <td @class(['cell-muted' => ! $asset->district])>{{ $asset->district?->district_name ?: 'Unassigned' }}</td>
                    <td @class(['cell-muted' => ! $asset->assignedTo])>{{ $asset->assignedTo?->full_name ?: 'Unassigned' }}</td>
                    <td><x-ui.status-pill domain="asset" :status="$asset->status ?: 'Unknown'" /></td>
                    <td class="nowrap cell-muted">{{ $asset->updated_at?->diffForHumans() }}</td>
                </tr>
            @empty
                <x-ui.empty-row :colspan="5" icon="history" title="No recent updates yet." />
            @endforelse
        </x-ui.table>
    </x-ui.card>
</div>
