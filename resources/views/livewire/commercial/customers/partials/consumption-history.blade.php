{{-- Expects $hs (the history figures), $rows (the consumption rows) and the dashboard's $n, $districtId. --}}
            @php $mult = fn ($v) => rtrim(rtrim(number_format($v, 2), '0'), '.'); @endphp
            <x-ui.card title="Against each account's own history" :description="'The average consumption of each billing account is compared with the mean of its own earlier months (the last '.$hs['window'].' months stored). History is stored from the first upload after this was switched on; an account is compared once it has '.$hs['needed'].' earlier months.'" class="dash-row">
                @if (! $hs['ready'])
                    <x-ui.alert tone="info">No account has a history baseline yet. This district has {{ $hs['months'] }} of the {{ $hs['needed'] }} earlier months it needs: the comparison starts with the upload after that, so nothing here means "no anomalies yet", only "nothing to compare with".</x-ui.alert>
                @else
                    @if ($hs['months'] < $hs['needed'])
                        <x-ui.alert tone="warning" class="dash-row">Some districts in view have fewer than {{ $hs['needed'] }} earlier months stored, so their accounts are not compared yet.</x-ui.alert>
                    @endif
                    <div class="ui-stat-grid dash-row">
                        <x-ui.stat-tile label="Accounts with a baseline" :value="$n($hs['baseline'])" icon="history" />
                        <x-ui.stat-tile label="Far above their own history" :value="$n($hs['spike'])" icon="trending-up" tone="warning" :meta="'More than '.$mult($hs['high_multiple']).' times their mean'" />
                        <x-ui.stat-tile label="Far below their own history" :value="$n($hs['drop'])" icon="trending-down" tone="info" :meta="'Under '.$mult($hs['low_multiple']).' times their mean, not zero'" />
                        <x-ui.stat-tile label="Zero against a positive history" :value="$n($hs['zero'])" icon="circle-slash" tone="danger" />
                    </div>
                    <x-ui.table label="Against own history by category" :sticky="false">
                        <x-slot:head><tr><th>Category</th><th class="num">With a baseline</th><th class="num">Far above</th><th class="num">Far below</th><th class="num">Zero</th></tr></x-slot:head>
                        @foreach ($rows as $row)
                            @if ($row['hist_baseline'] > 0)
                                <tr wire:key="kh-{{ $loop->index }}"><td>{{ $row['label'] }}</td><td class="num">{{ $n($row['hist_baseline']) }}</td><td class="num">{{ $n($row['hist_high']) }}</td><td class="num">{{ $n($row['hist_low']) }}</td><td class="num">{{ $n($row['hist_zero']) }}</td></tr>
                            @endif
                        @endforeach
                    </x-ui.table>
                    @if ($districtId)
                        <div class="dash-row" style="display:flex;gap:.5rem;flex-wrap:wrap">
                            @foreach (\App\Services\Commercial\Customers\CustomerAnalyticsService::HISTORY_LABELS as $issue => $label)
                                <a href="{{ route('commercial.customers.list', ['district' => $districtId, 'issue' => $issue]) }}" class="btn btn-ghost btn-sm">List: {{ $label }}</a>
                            @endforeach
                        </div>
                    @else
                        <p class="ui-hint">Pick a single district to list the accounts behind these counts.</p>
                    @endif
                @endif
                <p class="ui-hint">What is stored is the file's AVERAGE consumption at each upload (already smoothed by the billing system), not each month's metered use; a monthly consumption extract would make this sharper. Zero against a positive history can be a vacant or disconnected property: it is a list to review, not a finding.</p>
            </x-ui.card>
