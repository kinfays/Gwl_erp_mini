<div>
    <x-ui.page-header :title="$employee->full_name" :description="collect([$employee->staff_id, $employee->jobTitle?->job_title_name, $employee->department?->department_name, $employee->district?->district_name])->filter()->join(' · ')">
        <x-slot:actions>
            <a href="{{ route('assets.home') }}" class="btn btn-secondary">Back to dashboard</a>
        </x-slot:actions>
    </x-ui.page-header>

    <p class="ui-hint dash-row">{{ $total }} {{ \Illuminate\Support\Str::plural('device', $total) }} assigned.</p>

    @foreach ($groups as $category => [$title, $items])
        <x-ui.card :title="$title" :description="$items->count().' assigned'" :padded="false" class="dash-row">
            <x-ui.table :label="$title.' held by '.$employee->full_name" :sticky="false">
                <x-slot:head>
                    <tr>
                        <th>Device</th>
                        <th>Type / Model</th>
                        <th>Location</th>
                        <th>Status</th>
                        <th>Condition</th>
                        <th>Purchased</th>
                        <th>Warranty</th>
                    </tr>
                </x-slot:head>

                @forelse ($items as $asset)
                    <tr wire:key="emp-asset-{{ $asset->id }}">
                        <td>
                            <span class="ui-cell-stack">
                                <span class="ui-person-name">{{ $asset->asset_name }}</span>
                                <span class="ui-person-sub mono">{{ $asset->serial_number ?: 'No serial' }}</span>
                            </span>
                        </td>
                        <td>
                            <span class="ui-cell-stack">
                                <span>{{ \App\Models\IctAsset::ASSET_TYPES[$category][$asset->asset_type] ?? $asset->asset_type }}</span>
                                <span class="ui-person-sub">{{ $asset->assetModel?->name ?: 'No model' }}</span>
                            </span>
                        </td>
                        <td @class(['cell-muted' => ! $asset->district])>{{ $asset->district?->district_name ?: 'No district' }}</td>
                        <td><x-ui.status-pill domain="asset" :status="$asset->status" /></td>
                        <td @class(['cell-muted' => ! $asset->condition])>{{ $asset->condition ?: 'Not recorded' }}</td>
                        <td class="nowrap {{ $asset->purchased_at ? '' : 'cell-muted' }}">{{ $asset->purchased_at?->format('d M Y') ?: 'Unknown' }}</td>
                        <td class="nowrap {{ $asset->warranty_expires_at ? '' : 'cell-muted' }}">{{ $asset->warranty_expires_at?->format('d M Y') ?: 'Unknown' }}</td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="7" icon="laptop" :title="'No '.strtolower($title).' assigned.'" />
                @endforelse
            </x-ui.table>
        </x-ui.card>
    @endforeach
</div>
