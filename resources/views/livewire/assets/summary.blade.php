<div>
    @assets
        @vite('resources/js/charts.js')
    @endassets

    <x-ui.page-header title="Asset Summary" description="Lifecycle, assignment, manufacturers, maintenance and reported issues in one place.">
        <x-slot:actions>
            @php $exportQuery = array_filter(['district' => $district, 'from' => $from, 'to' => $to], fn ($v) => $v !== ''); @endphp
            <a href="{{ route('assets.summary.export.excel', $exportQuery) }}" class="btn btn-secondary">
                <x-ui.icon name="file-spreadsheet" />
                Excel
            </a>
            <a href="{{ route('assets.summary.export.pdf', $exportQuery) }}" class="btn btn-secondary">
                <x-ui.icon name="file-text" />
                PDF
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($needsAttention->isNotEmpty())
        <x-ui.card title="Needs Attention" description="Damaged, in poor condition, or with an unresolved issue report. Newest activity first." :padded="false" class="dash-row">
            <x-ui.table label="Assets needing attention" :sticky="false">
                <x-slot:head>
                    <tr>
                        <th>Asset</th>
                        <th>District</th>
                        <th>Why</th>
                    </tr>
                </x-slot:head>
                @foreach ($needsAttention as $row)
                    @php $asset = $row['asset']; @endphp
                    <tr wire:key="attention-{{ $asset->id }}">
                        <td>
                            <a href="{{ route('assets.show', $asset) }}">
                                <span class="ui-cell-stack">
                                    <span class="ui-person-name">{{ $asset->asset_name }}</span>
                                    <span class="ui-person-sub mono">{{ $asset->serial_number ?: 'No serial' }}</span>
                                </span>
                            </a>
                        </td>
                        <td @class(['cell-muted' => ! $asset->district])>{{ $asset->district?->district_name ?: 'No district' }}</td>
                        <td>
                            <span class="ui-tags">
                                @foreach ($row['reasons'] as $reason)
                                    <x-ui.badge>{{ $reason }}</x-ui.badge>
                                @endforeach
                            </span>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif

    <div class="ui-toolbar dash-row" role="search" aria-label="Summary filters">
        <x-ui.segmented label="Summary section" wire:model.live="tab" :options="$tabs" />
        <select wire:model.live="district" class="form-input" aria-label="District">
            <option value="">All districts</option>
            @foreach ($districts as $d)
                <option value="{{ $d->id }}">{{ $d->district_name }}</option>
            @endforeach
        </select>
        @if ($showDates)
            <input type="date" wire:model.live="from" class="form-input" aria-label="From date">
            <input type="date" wire:model.live="to" class="form-input" aria-label="To date">
        @endif
        @if ($district !== '' || $from !== '' || $to !== '')
            <button type="button" wire:click="clearFilters" class="btn btn-ghost btn-sm">Clear filters</button>
        @endif
    </div>

    @if ($activeTab === 'lifecycle')
        <div class="ui-grid ui-grid-2 dash-row">
            <x-ui.card title="Asset Age" description="Years since purchase date, across assets, phones and network devices." :padded="false">
                <x-assets.bucket-table label="Assets by age" first-column="Age" :rows="$ageBuckets" />
            </x-ui.card>

            <x-ui.card title="Warranty Status" description="By warranty expiry date; Unknown means no expiry is recorded." :padded="false">
                <x-assets.bucket-table label="Assets by warranty status" first-column="Warranty" :rows="$warrantyBuckets" />
            </x-ui.card>
        </div>

        <x-ui.card title="Replacement Forecast" description="When each device reaches its replacement age under the ICT replacement policy." :padded="false" class="dash-row">
            <x-slot:actions>
                @if ($canEditPolicy)
                    <a href="{{ route('assets.settings.replacement-policy') }}" class="btn btn-ghost btn-sm">Edit policy</a>
                @endif
            </x-slot:actions>
            <x-assets.bucket-table label="Assets by replacement due date" first-column="Replacement" :rows="$replacementBuckets" />
        </x-ui.card>
    @elseif ($activeTab === 'assignment')
        <div class="ui-grid ui-grid-2 dash-row">
            <x-ui.card title="Unassigned" description="Devices with no employee recorded as the holder.">
                <x-ui.stat-tile label="Unassigned" :value="$unassigned['total']" icon="laptop" tone="warning" />
            </x-ui.card>

            <x-ui.card title="Employees With the Most Assets" description="Top 10 holders across all three categories." :padded="false">
                <x-ui.table label="Employees with the most assets" :sticky="false">
                    <x-slot:head>
                        <tr>
                            <th>Employee</th>
                            <th class="num">Assets held</th>
                        </tr>
                    </x-slot:head>

                    @forelse ($topAssignees as $row)
                        <tr>
                            <td><a href="{{ route('assets.employee', $row['employee_id']) }}">{{ $row['name'] }}</a></td>
                            <td class="num"><strong>{{ $row['total'] }}</strong></td>
                        </tr>
                    @empty
                        <x-ui.empty-row :colspan="2" icon="users" title="No assets are assigned yet." />
                    @endforelse
                </x-ui.table>
            </x-ui.card>
        </div>
    @elseif ($activeTab === 'manufacturers')
        <p class="ui-hint dash-row">Share of devices by manufacturer. Only devices with a model recorded are counted; each card says how many that is.</p>

        <div class="ui-grid ui-grid-2 dash-row">
            @foreach ($manufacturerGroups as $category => $group)
                @continue($category === 'network' && $group['result']['with_model'] === 0)
                @php
                    $result = $group['result'];
                    $cover = $result['coverage'][$category];
                    $groupKey = md5(json_encode($group['slices']));
                @endphp

                <x-ui.card :title="$group['title']" :description="$cover['with_model'].' of '.$cover['total'].' devices have a model recorded'" wire:key="mfr-card-{{ $category }}" x-data="{ showTable: false }">
                    <x-slot:actions>
                        <button type="button" class="btn btn-ghost btn-sm" x-on:click="showTable = ! showTable" x-bind:aria-pressed="showTable.toString()">
                            <x-ui.icon name="table" class="icon-sm" x-show="! showTable" />
                            <x-ui.icon name="chart-column" class="icon-sm" x-show="showTable" x-cloak />
                            <span x-text="showTable ? 'Chart' : 'Table'">Table</span>
                            <span class="sr-only-text">view of {{ $group['title'] }} by manufacturer</span>
                        </button>
                    </x-slot:actions>

                    <div x-show="! showTable">
                        <div wire:key="mfr-chart-{{ $category }}-{{ $groupKey }}">
                            <x-ui.chart type="doughnut" :label="$group['title'].' by manufacturer'" center center-caption="devices"
                                :labels="$group['slices']['labels']"
                                :series="[['label' => 'Devices', 'data' => $group['slices']['data'], 'colors' => $group['slices']['colors']]]"
                                :table="false" height="280" empty-text="No devices with a model recorded." />
                        </div>
                    </div>

                    <div x-show="showTable" x-cloak>
                        <x-ui.table :label="$group['title'].' by manufacturer and model'" :sticky="false">
                            <x-slot:head>
                                <tr>
                                    <th>Manufacturer / Model</th>
                                    <th class="num">Devices</th>
                                    <th class="num">Share</th>
                                </tr>
                            </x-slot:head>

                            @forelse ($result['rows'] as $row)
                                <tr wire:key="mfr-{{ $category }}-{{ $loop->index }}">
                                    <td><strong>{{ $row['manufacturer'] }}</strong></td>
                                    <td class="num"><strong>{{ $row['total'] }}</strong></td>
                                    <td class="num"><strong>{{ $row['percentage'] }}%</strong></td>
                                </tr>
                                @foreach ($row['models'] as $model)
                                    <tr wire:key="mfr-{{ $category }}-{{ $loop->parent->index }}-{{ $loop->index }}">
                                        <td style="padding-left: 2rem;">{{ $model['model'] }}</td>
                                        <td class="num">{{ $model['total'] }}</td>
                                        <td class="num">{{ $model['percentage'] }}%</td>
                                    </tr>
                                @endforeach
                            @empty
                                <x-ui.empty-row :colspan="3" icon="boxes" title="No devices with a model recorded." />
                            @endforelse
                        </x-ui.table>
                    </div>
                </x-ui.card>
            @endforeach
        </div>
    @elseif ($activeTab === 'maintenance')
        <div class="ui-stat-grid dash-row">
            <x-ui.stat-tile label="Tickets" :value="$maintenance['total']" icon="wrench" tone="primary" />
            <x-ui.stat-tile label="Average turnaround"
                :value="$maintenance['avg_turnaround_days'] !== null ? $maintenance['avg_turnaround_days'].' days' : '—'"
                icon="clock" tone="info"
                :meta="$maintenance['completed_counted'].' completed '.\Illuminate\Support\Str::plural('ticket', $maintenance['completed_counted']).' with a completion date'" />
        </div>

        <div class="ui-grid ui-grid-2 dash-row">
            <x-ui.card title="By Status" :padded="false">
                <x-ui.table label="Maintenance tickets by status" :sticky="false">
                    <x-slot:head>
                        <tr><th>Status</th><th class="num">Tickets</th></tr>
                    </x-slot:head>
                    @forelse ($maintenance['by_status'] as $status => $total)
                        <tr><td>{{ $status }}</td><td class="num">{{ $total }}</td></tr>
                    @empty
                        <x-ui.empty-row :colspan="2" icon="wrench" title="No maintenance tickets for these filters." />
                    @endforelse
                </x-ui.table>
            </x-ui.card>

            <x-ui.card title="Most Repaired Assets" description="Tickets per asset." :padded="false">
                <x-ui.table label="Most repaired assets" :sticky="false">
                    <x-slot:head>
                        <tr><th>Asset</th><th class="num">Tickets</th></tr>
                    </x-slot:head>
                    @forelse ($maintenance['top_assets'] as $row)
                        <tr>
                            <td>
                                <a href="{{ route('assets.show', $row['asset']) }}">
                                    {{ $row['asset']->asset_name }}
                                </a>
                                <span class="ui-person-sub mono">{{ $row['asset']->serial_number }}</span>
                            </td>
                            <td class="num"><strong>{{ $row['total'] }}</strong></td>
                        </tr>
                    @empty
                        <x-ui.empty-row :colspan="2" icon="wrench" title="No maintenance tickets for these filters." />
                    @endforelse
                </x-ui.table>
            </x-ui.card>
        </div>

        <x-ui.card title="Monthly Volume" description="Tickets opened per month.">
            <div wire:key="maintenance-chart-{{ md5(json_encode($maintenance['monthly'])) }}">
                <x-ui.chart type="bar" label="Maintenance tickets per month"
                    :labels="$maintenance['monthly']['labels']"
                    :series="[['label' => 'Tickets', 'data' => $maintenance['monthly']['data']]]"
                    height="240" empty-text="No tickets in this period." />
            </div>
        </x-ui.card>
    @elseif ($activeTab === 'issues')
        <div class="ui-stat-grid dash-row">
            <x-ui.stat-tile label="Reported issues" :value="$issues['total']" icon="triangle-alert" tone="warning" />
        </div>

        <div class="ui-grid ui-grid-2 dash-row">
            <x-ui.card title="By Type" :padded="false">
                <x-ui.table label="Reported issues by type" :sticky="false">
                    <x-slot:head>
                        <tr><th>Issue type</th><th class="num">Reports</th></tr>
                    </x-slot:head>
                    @foreach ($issues['by_type'] as $type => $total)
                        <tr><td>{{ $type }}</td><td class="num">{{ $total }}</td></tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>

            <x-ui.card title="By Status" :padded="false">
                <x-ui.table label="Reported issues by status" :sticky="false">
                    <x-slot:head>
                        <tr><th>Status</th><th class="num">Reports</th></tr>
                    </x-slot:head>
                    @forelse ($issues['by_status'] as $status => $total)
                        <tr><td>{{ $status }}</td><td class="num">{{ $total }}</td></tr>
                    @empty
                        <x-ui.empty-row :colspan="2" icon="triangle-alert" title="No issue reports for these filters." />
                    @endforelse
                </x-ui.table>
            </x-ui.card>
        </div>

        <x-ui.card title="Monthly Volume" description="Reports raised per month.">
            <div wire:key="issues-chart-{{ md5(json_encode($issues['monthly'])) }}">
                <x-ui.chart type="bar" label="Issue reports per month"
                    :labels="$issues['monthly']['labels']"
                    :series="[['label' => 'Reports', 'data' => $issues['monthly']['data']]]"
                    height="240" empty-text="No reports in this period." />
            </div>
        </x-ui.card>
    @endif
</div>
