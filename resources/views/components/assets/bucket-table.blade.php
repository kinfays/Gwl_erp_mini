{{--
    Bucket counts split by inventory screen, each non-zero count a plain link to that screen filtered to the bucket
    (query params come from AssetDashboardService, the same rules the lists apply). Total is the sum across screens.
        <x-assets.bucket-table label="Assets by age" first-column="Age" :rows="$ageBuckets" />
--}}
@props(['rows' => [], 'label', 'firstColumn' => 'Bucket'])

@php
    $screens = [
        \App\Models\IctAsset::DEVICE_CATEGORY_ASSET => 'Assets',
        \App\Models\IctAsset::DEVICE_CATEGORY_PHONE => 'Phones',
        \App\Models\IctAsset::DEVICE_CATEGORY_NETWORK => 'Network',
    ];
@endphp

<x-ui.table :label="$label" :sticky="false">
    <x-slot:head>
        <tr>
            <th>{{ $firstColumn }}</th>
            @foreach ($screens as $name)
                <th class="num">{{ $name }}</th>
            @endforeach
            <th class="num">Total</th>
        </tr>
    </x-slot:head>

    @foreach ($rows as $row)
        <tr>
            <td class="nowrap">{{ $row['label'] }}</td>
            @foreach ($screens as $category => $name)
                <td class="num">
                    @if ($row['by_category'][$category] > 0)
                        <a href="{{ route(\App\Services\Assets\AssetDashboardService::CATEGORY_ROUTES[$category], $row['params']) }}">{{ $row['by_category'][$category] }}</a>
                    @else
                        <span class="cell-muted">0</span>
                    @endif
                </td>
            @endforeach
            <td class="num"><strong>{{ $row['total'] }}</strong></td>
        </tr>
    @endforeach
</x-ui.table>
