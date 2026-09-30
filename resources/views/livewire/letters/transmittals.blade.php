<div>
    <x-ui.page-header title="Transmittals" description="Hardcopies handed to your desk, and the hand-over sheets you have sent.">
        <x-slot:actions>
            <a href="{{ route('letters.active') }}" class="btn btn-secondary">
                <x-ui.icon name="inbox" />
                Active Letters
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($missingEmployee)
        <x-ui.alert tone="danger">Your user account is not linked to an employee record.</x-ui.alert>
    @else
        <div class="tabs" role="group" aria-label="Transmittals">
            <button type="button" wire:click="setTab('incoming')" @class(['tab', 'active' => $tab === 'incoming']) aria-pressed="{{ $tab === 'incoming' ? 'true' : 'false' }}">
                Incoming @if ($pendingTotal > 0)<x-ui.badge tone="warning">{{ $pendingTotal }}</x-ui.badge>@endif
            </button>
            <button type="button" wire:click="setTab('sent')" @class(['tab', 'active' => $tab === 'sent']) aria-pressed="{{ $tab === 'sent' ? 'true' : 'false' }}">Sent</button>
        </div>

        @if ($tab === 'incoming')
            <div class="ui-stack" wire:key="incoming">
                @forelse ($groups as $group)
                    @php
                        $batch = $group['batch'];
                        $hops = $group['hops'];
                        $tickedCount = $hops->reject(fn ($hop) => in_array($hop->id, $unticked, true))->count();
                    @endphp
                    <x-ui.card
                        :padded="false"
                        :title="$batch ? $batch->batch_no.' · from '.$hops->first()->fromSecretariat?->full_name : 'Individual letters'"
                        :description="$batch
                            ? $hops->count().' of '.$batch->letters_count.' waiting · dispatched '.$batch->dispatched_at?->format('d M Y H:i').($batch->note ? ' · '.$batch->note : '')
                            : 'Dispatched to you one at a time'"
                        :class="$batch && $focusBatch === $batch->id ? 'is-focused' : ''"
                        wire:key="group-{{ $group['key'] }}"
                    >
                        <x-slot:actions>
                            @if ($batch)
                                <a href="{{ route('letters.transmittals.sheet', $batch) }}" target="_blank" rel="noopener" class="btn btn-sm btn-ghost">
                                    <x-ui.icon name="printer" class="icon-sm" />
                                    Sheet
                                </a>
                            @endif
                            <button type="button" wire:click="confirmGroup('{{ $group['key'] }}', true)" wire:loading.attr="disabled" class="btn btn-sm" @disabled($tickedCount === 0)>
                                Confirm ticked ({{ $tickedCount }})
                            </button>
                            <button type="button" wire:click="confirmGroup('{{ $group['key'] }}')" wire:loading.attr="disabled" class="btn btn-sm btn-primary">
                                <x-ui.icon name="clipboard-check" class="icon-sm" />
                                Confirm all ({{ $hops->count() }})
                            </button>
                        </x-slot:actions>

                        <x-ui.table label="Letters waiting for your confirmation" :sticky="false" dense>
                            <x-slot:head>
                                <tr>
                                    <th class="bulk-check"><span class="sr-only-text">Received</span></th>
                                    <th>SN#</th>
                                    <th>Subject</th>
                                    <th>Ref No</th>
                                    <th>Sender</th>
                                    @unless ($batch)
                                        <th>From</th>
                                        <th>Dispatched</th>
                                    @endunless
                                </tr>
                            </x-slot:head>
                            @foreach ($hops as $hop)
                                <tr wire:key="hop-{{ $hop->id }}">
                                    <td class="bulk-check">
                                        <input
                                            type="checkbox"
                                            wire:click="toggleLine({{ $hop->id }})"
                                            @checked(! in_array($hop->id, $unticked, true))
                                            aria-label="Hardcopy of {{ $hop->letter?->sn_number }} received"
                                        >
                                    </td>
                                    <td class="mono nowrap">{{ $hop->letter?->sn_number }}</td>
                                    <td>{{ $hop->letter?->subject }}</td>
                                    <td @class(['mono', 'cell-muted' => ! $hop->letter?->ref_no])>{{ $hop->letter?->ref_no ?: '-' }}</td>
                                    <td>{{ $hop->letter?->sender_name }}</td>
                                    @unless ($batch)
                                        <td class="nowrap">{{ $hop->fromSecretariat?->full_name }}</td>
                                        <td class="nowrap cell-muted">{{ $hop->created_at?->format('d M Y H:i') }}</td>
                                    @endunless
                                </tr>
                            @endforeach
                        </x-ui.table>
                    </x-ui.card>
                @empty
                    <x-ui.card>
                        <x-ui.empty-state icon="clipboard-check" title="Nothing is waiting for your confirmation." description="Letters handed to your desk appear here until you confirm the hardcopy." />
                    </x-ui.card>
                @endforelse
            </div>
        @else
            <div class="ui-stack" wire:key="sent">
                @forelse ($sent as $batch)
                    @php
                        $complete = $batch->isComplete();
                        $partial = ! $complete && $batch->confirmed_count > 0;
                    @endphp
                    <x-ui.card
                        :padded="false"
                        :title="$batch->batch_no.' · to '.$batch->toSecretariat?->full_name"
                        :description="'Dispatched '.$batch->dispatched_at?->format('d M Y H:i').($batch->note ? ' · '.$batch->note : '')"
                        :class="$focusBatch === $batch->id ? 'is-focused' : ''"
                        wire:key="sent-{{ $batch->id }}"
                    >
                        <x-slot:actions>
                            <x-ui.status-pill
                                :tone="$complete ? 'success' : ($partial ? 'warning' : 'muted')"
                                :label="$batch->confirmed_count.' of '.$batch->letters_count.' confirmed'"
                            />
                            <a href="{{ route('letters.transmittals.sheet', $batch) }}" target="_blank" rel="noopener" class="btn btn-sm">
                                <x-ui.icon name="printer" class="icon-sm" />
                                Print sheet
                            </a>
                        </x-slot:actions>

                        <x-ui.table label="Letters in this transmittal" :sticky="false" dense>
                            <x-slot:head>
                                <tr>
                                    <th>SN#</th>
                                    <th>Subject</th>
                                    <th>Status</th>
                                </tr>
                            </x-slot:head>
                            @foreach ($batch->routingHistories as $hop)
                                <tr wire:key="sent-hop-{{ $hop->id }}">
                                    <td class="mono nowrap">{{ $hop->letter?->sn_number }}</td>
                                    <td>{{ $hop->letter?->subject }}</td>
                                    <td class="nowrap">
                                        @if ($hop->received_confirm)
                                            <x-ui.status-pill tone="success" label="Confirmed" />
                                            <span class="ui-hint">{{ $hop->confirmed_at?->format('d M Y H:i') }}</span>
                                        @else
                                            <x-ui.status-pill tone="warning" label="Awaiting hardcopy" />
                                            <span class="ui-hint">unconfirmed for {{ $hop->created_at?->diffForHumans(null, true) }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </x-ui.table>
                    </x-ui.card>
                @empty
                    <x-ui.card>
                        <x-ui.empty-state icon="send" title="You have not sent any transmittals." description="Select several letters on Active Letters and use Dispatch selected." />
                    </x-ui.card>
                @endforelse

                @if ($sent && $sent->hasPages())
                    <div>{{ $sent->links() }}</div>
                @endif
            </div>
        @endif
    @endif
</div>
