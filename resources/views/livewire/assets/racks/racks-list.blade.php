<div>
    <x-ui.page-header title="Racks" description="Network cabinets and server racks, and how much of each is in use.">
        @if ($canManage)
            <x-slot:actions>
                <a href="{{ route('assets.racks.create') }}" class="btn btn-primary">
                    <x-ui.icon name="plus" />
                    Add Rack
                </a>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <x-ui.card :padded="false">
        <div class="ui-toolbar" role="search" aria-label="Filter racks">
            <div class="ui-input-wrap toolbar-grow">
                <x-ui.icon name="search" class="ui-input-icon" />
                <input type="text" wire:model.live.debounce.300ms="search" class="form-input ui-input has-icon" placeholder="Search name, code, location, notes" aria-label="Search racks">
            </div>
            <select wire:model.live="status" class="form-input" aria-label="Status">
                <option value="">All statuses</option>
                @foreach ($statusOptions as $option)
                    <option value="{{ $option }}">{{ $option }}</option>
                @endforeach
            </select>
            <x-ui.multi-select label="District" all-label="All districts" :options="$districtOptions" :selected="$selectedDistricts" toggle="toggleDistrict" clear="clearDistricts" select="selectAllDistricts" />
            <select wire:model.live="perPage" class="form-input" aria-label="Rows per page">
                <option value="15">15 per page</option>
                <option value="30">30 per page</option>
                <option value="50">50 per page</option>
            </select>
            @if ($canExport)
                <a href="{{ route('assets.racks.export.excel', $exportParams) }}" class="btn btn-secondary btn-icon" title="Export Excel" aria-label="Export Excel">
                    <x-ui.file-icon type="excel" />
                </a>
                <a href="{{ route('assets.racks.export.pdf', $exportParams) }}" class="btn btn-secondary btn-icon" title="Export PDF" aria-label="Export PDF">
                    <x-ui.file-icon type="pdf" />
                </a>
            @endif
            <p class="pager-total" role="status">
                <b>{{ number_format($racks->total()) }}</b> {{ $racks->total() === 1 ? 'rack' : 'racks' }}
                {{ $filtersActive ? 'match your filters' : 'in total' }}
            </p>
        </div>

        <div class="ui-loading-host">
            <x-ui.table label="Racks" pin-first>
                <x-slot:head>
                    <tr>
                        <th>Rack</th>
                        @if (in_array('location', $visibleColumns))
                            <th>Location</th>
                        @endif
                        @if (in_array('district', $visibleColumns))
                            <th>District</th>
                        @endif
                        @if (in_array('usage', $visibleColumns))
                            <th>Used / Total U</th>
                        @endif
                        @if (in_array('devices', $visibleColumns))
                            <th class="num">Devices</th>
                        @endif
                        @if (in_array('status', $visibleColumns))
                            <th>Status</th>
                        @endif
                        <th class="actions">
                            <div class="ui-th-actions">
                                <span>Action</span>
                                <x-ui.column-picker :columns="$columnManifest" :visible="$visibleColumns" :mode="$columnMode" />
                            </div>
                        </th>
                    </tr>
                </x-slot:head>

                @forelse ($racks as $rack)
                    <tr wire:key="rack-{{ $rack->id }}">
                        <td>
                            <span class="ui-cell-stack">
                                <a href="{{ route('assets.racks.show', $rack) }}" class="ui-person-name">{{ $rack->name }}</a>
                                <span class="ui-person-sub mono">{{ $rack->code ?: 'No code' }}</span>
                            </span>
                        </td>
                        @if (in_array('location', $visibleColumns))
                            <td>{{ $rack->location ?: '—' }}</td>
                        @endif
                        @if (in_array('district', $visibleColumns))
                            <td>
                                <span class="ui-cell-stack">
                                    <span>{{ $rack->district?->district_name ?: 'No district' }}</span>
                                    @if ($seesAllRegions && $rack->region)
                                        <span class="ui-person-sub">{{ $rack->region->region_name }}</span>
                                    @endif
                                </span>
                            </td>
                        @endif
                        @if (in_array('usage', $visibleColumns))
                            <td>
                                <span class="ui-cell-stack">
                                    <span>{{ $rack->usedUnits() }} / {{ $rack->total_units }} U</span>
                                    <span class="ui-person-sub">{{ $rack->freeUnits() }} U free</span>
                                </span>
                            </td>
                        @endif
                        @if (in_array('devices', $visibleColumns))
                            <td class="num">{{ $rack->devices->count() }}</td>
                        @endif
                        @if (in_array('status', $visibleColumns))
                            <td><x-ui.status-pill domain="rack" :status="$rack->status" /></td>
                        @endif
                        <td class="actions">
                            @php($inWriteScope = ! $regionLimited || (int) $rack->region_id === (int) $ownRegionId)
                            <a href="{{ route('assets.racks.show', $rack) }}" class="btn btn-ghost btn-sm btn-icon" title="View" aria-label="View {{ $rack->name }}">
                                <x-ui.icon name="eye" />
                            </a>
                            @if ($canManage && $inWriteScope)
                                <a href="{{ route('assets.racks.edit', $rack) }}" class="btn btn-ghost btn-sm btn-icon" title="Edit" aria-label="Edit {{ $rack->name }}">
                                    <x-ui.icon name="pencil" />
                                </a>
                                <button type="button" wire:click="confirmDelete({{ $rack->id }})" class="btn btn-ghost btn-sm btn-icon" title="Delete" aria-label="Delete {{ $rack->name }}">
                                    <x-ui.icon name="trash-2" />
                                </button>
                            @elseif ($canManage)
                                <span class="ui-hint">Read only</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="count($visibleColumns)" icon="server" title="No racks found for your filters." description="Try a different search or clear the filters." />
                @endforelse

                <x-slot:footer>
                    <p class="pager-summary">
                        Showing {{ $racks->firstItem() ?? 0 }} - {{ $racks->lastItem() ?? 0 }} of {{ $racks->total() }} racks
                    </p>
                    <div>{{ $racks->links() }}</div>
                </x-slot:footer>
            </x-ui.table>

            <div wire:loading.delay class="table-skeleton">
                <span class="skeleton-line"></span>
                <span class="skeleton-line"></span>
                <span class="skeleton-line short"></span>
                <x-loading.drop-fill class="wl-veil-drop" label="Loading" />
            </div>
        </div>
    </x-ui.card>

    @if ($deletingRackId)
        <x-ui.modal title="Delete rack" close="cancelDelete()" size="sm" icon="trash-2" tone="danger">
            <p>Delete <strong>{{ $deletingName }}</strong>? This cannot be undone.</p>
            <p class="ui-hint">A rack that still has devices mounted in it cannot be deleted. Move them to another rack first.</p>

            @error('delete')
                <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert>
            @enderror

            <x-slot:footer>
                <button type="button" class="btn btn-ghost" wire:click="cancelDelete">Cancel</button>
                <button type="button" class="btn btn-danger-solid" wire:click="deleteRack" wire:loading.attr="disabled" wire:target="deleteRack">Delete rack</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
