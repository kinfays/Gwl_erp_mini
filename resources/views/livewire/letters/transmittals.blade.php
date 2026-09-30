<div>
    <x-ui.page-header title="Transmittals" description="Hardcopies handed to your desk, and the hand-overs you have sent.">
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
        @php
            // "waiting 3 days" / "waiting today", toned amber after the alert days and red at twice that.
            $waiting = fn ($since) => ($days = $workflow->waitingDays($since)) === 0 ? 'waiting today' : 'waiting '.$days.' '.\Illuminate\Support\Str::plural('day', $days);
        @endphp

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
                            <button type="button" wire:click="openReject('{{ $group['key'] }}')" class="btn btn-sm btn-ghost" @disabled($tickedCount === 0)>
                                Reject ticked…
                            </button>
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
                                    @endunless
                                    <th>Waiting</th>
                                    @if ($showScanLinks)
                                        <th>Scan</th>
                                    @endif
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
                                    @endunless
                                    <td class="nowrap">
                                        <x-ui.badge :tone="$workflow->agingTone($hop->created_at) ?? 'neutral'" title="Dispatched {{ $hop->created_at?->format('d M Y H:i') }}">{{ $waiting($hop->created_at) }}</x-ui.badge>
                                    </td>
                                    @if ($showScanLinks)
                                        @php
                                            $lineScans = $hop->letter?->scans ?? collect();
                                        @endphp
                                        <td class="nowrap">
                                            @if ($lineScans->count() === 1)
                                                <a href="{{ route('letters.scans.show', $lineScans->first()) }}" target="_blank" rel="noopener" class="row-link"><x-ui.icon name="paperclip" class="icon-sm" /> View scan</a>
                                            @elseif ($lineScans->count() > 1)
                                                <a href="{{ route('letters.active', ['letter' => $hop->letter_id, 'prompt' => 1]) }}" class="row-link"><x-ui.icon name="paperclip" class="icon-sm" /> View scans ({{ $lineScans->count() }})</a>
                                            @else
                                                <span class="cell-muted">-</span>
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </x-ui.table>

                        @if ($rejectGroup === $group['key'])
                            <div class="reject-panel ui-stack" wire:key="reject-{{ $group['key'] }}">
                                <x-ui.textarea
                                    label="Why are you rejecting the ticked {{ $tickedCount === 1 ? 'letter' : $tickedCount.' letters' }}?"
                                    wire:model="rejectReason"
                                    rows="2"
                                    maxlength="500"
                                    placeholder="e.g. Meant for the Materials desk, not ours"
                                    hint="The sender is told this reason and the letter goes back to their desk. You cannot undo it."
                                />
                                <div class="ui-form-actions">
                                    <button type="button" wire:click="cancelReject" class="btn btn-secondary">Cancel</button>
                                    <button type="button" wire:click="rejectTicked" wire:loading.attr="disabled" class="btn btn-danger" @disabled($tickedCount === 0)>
                                        Reject {{ $tickedCount }} {{ \Illuminate\Support\Str::plural('letter', $tickedCount) }}
                                    </button>
                                </div>
                            </div>
                        @endif
                    </x-ui.card>
                @empty
                    <x-ui.card>
                        <x-ui.empty-state icon="clipboard-check" title="Nothing is waiting for your confirmation." description="Letters handed to your desk appear here until you confirm the hardcopy." />
                    </x-ui.card>
                @endforelse
            </div>
        @else
            <div class="tabs" role="group" aria-label="Sent filter">
                <button type="button" wire:click="setSentFilter('')" @class(['tab', 'active' => $sentFilter === '']) aria-pressed="{{ $sentFilter === '' ? 'true' : 'false' }}">All</button>
                <button type="button" wire:click="setSentFilter('overdue')" @class(['tab', 'active' => $sentFilter === 'overdue']) aria-pressed="{{ $sentFilter === 'overdue' ? 'true' : 'false' }}">
                    Overdue @if ($overdueCount > 0)<x-ui.badge tone="warning">{{ $overdueCount }}</x-ui.badge>@endif
                </button>
            </div>

            @php
                // A line that is still waiting: pill toned by age, and a Recall button for people who may dispatch.
                $confirmRecall = fn (int $hopId, string $sn) => "\$dispatch('confirm-action', { title: 'Recall this letter?', message: ".\Illuminate\Support\Js::from($sn.' goes back to your desk and the recipient can no longer confirm it.').", confirmLabel: 'Recall', variant: 'danger', action: () => \$wire.recallLine({$hopId}) })";
            @endphp

            <div class="ui-stack" wire:key="sent">
                @if ($sentSingles->isNotEmpty())
                    @php
                        $singleCooldown = fn ($hop) => $workflow->remindCooldownEndsAt([$hop]);
                    @endphp
                    <x-ui.card :padded="false" title="Individual letters" description="Dispatched one at a time and not yet confirmed" wire:key="sent-singles">
                        <x-ui.table label="Individual letters waiting for confirmation" :sticky="false" dense>
                            <x-slot:head>
                                <tr>
                                    <th>SN#</th>
                                    <th>Subject</th>
                                    <th>To</th>
                                    <th>Status</th>
                                    <th class="actions"><span class="sr-only-text">Actions</span></th>
                                </tr>
                            </x-slot:head>
                            @foreach ($sentSingles as $hop)
                                @php
                                    $until = $singleCooldown($hop);
                                @endphp
                                <tr wire:key="single-{{ $hop->id }}">
                                    <td class="mono nowrap">{{ $hop->letter?->sn_number }}</td>
                                    <td>{{ $hop->letter?->subject }}</td>
                                    <td class="nowrap">{{ $hop->toSecretariat?->full_name }}</td>
                                    <td class="nowrap">
                                        <x-ui.status-pill :tone="$workflow->agingTone($hop->created_at) ?? 'info'" label="Awaiting hardcopy" />
                                        <span class="ui-hint">{{ $waiting($hop->created_at) }}</span>
                                    </td>
                                    <td class="actions">
                                        @if ($canForward)
                                            <div class="row-actions">
                                                <button type="button" wire:click="remindLine({{ $hop->id }})" class="btn btn-sm btn-ghost" @disabled($until) @if ($until) title="Reminded {{ $hop->reminded_at->diffForHumans() }}; you can remind again {{ $workflow->waitUntilLabel($until) }}" @endif>
                                                    <x-ui.icon name="bell" class="icon-sm" />
                                                    Remind
                                                </button>
                                                <button type="button" class="btn btn-sm btn-ghost is-danger" x-data x-on:click.prevent="{{ $confirmRecall($hop->id, (string) $hop->letter?->sn_number) }}">
                                                    <x-ui.icon name="undo-2" class="icon-sm" />
                                                    Recall
                                                </button>
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </x-ui.table>
                    </x-ui.card>
                @endif

                @foreach ($sent as $batch)
                    @php
                        $complete = $batch->isComplete();
                        $partial = ! $complete && $batch->confirmed_count > 0;
                        $lines = $batch->routingHistories;
                        $awaitingLines = $lines->filter(fn ($line) => $line->isAwaiting());
                        $resolvedCount = $lines->filter(fn ($line) => $line->isResolved())->count();
                        $until = $awaitingLines->isNotEmpty() ? $workflow->remindCooldownEndsAt($awaitingLines) : null;
                        $lastReminder = $awaitingLines->pluck('reminded_at')->filter()->max();
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
                                :tone="$complete ? ($batch->confirmed_count > 0 ? 'success' : 'muted') : ($partial ? 'warning' : 'muted')"
                                :label="$batch->confirmed_count.' of '.$batch->letters_count.' confirmed'.($resolvedCount ? ' · '.$resolvedCount.' recalled/rejected' : '')"
                            />
                            @if ($canForward && $awaitingLines->isNotEmpty())
                                <button type="button" wire:click="remindBatch({{ $batch->id }})" wire:loading.attr="disabled" class="btn btn-sm btn-ghost" @disabled($until) @if ($until) title="Reminded {{ $lastReminder->diffForHumans() }}; you can remind again {{ $workflow->waitUntilLabel($until) }}" @endif>
                                    <x-ui.icon name="bell" class="icon-sm" />
                                    Remind
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-ghost is-danger"
                                    x-data
                                    x-on:click.prevent="$dispatch('confirm-action', {
                                        title: 'Recall unconfirmed lines?',
                                        message: @js($awaitingLines->count().' '.\Illuminate\Support\Str::plural('letter', $awaitingLines->count()).' of '.$batch->batch_no.' that '.$batch->toSecretariat?->full_name.' has not confirmed will come back to your desk.'),
                                        confirmLabel: 'Recall',
                                        variant: 'danger',
                                        action: () => $wire.recallBatch({{ $batch->id }})
                                    })"
                                >
                                    <x-ui.icon name="undo-2" class="icon-sm" />
                                    Recall unconfirmed lines ({{ $awaitingLines->count() }})
                                </button>
                            @endif
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
                                    <th class="actions"><span class="sr-only-text">Actions</span></th>
                                </tr>
                            </x-slot:head>
                            @foreach ($lines as $hop)
                                <tr wire:key="sent-hop-{{ $hop->id }}">
                                    <td class="mono nowrap">{{ $hop->letter?->sn_number }}</td>
                                    <td>{{ $hop->letter?->subject }}</td>
                                    <td class="nowrap">
                                        @if ($hop->isResolved())
                                            <x-ui.status-pill tone="muted" :label="$hop->resolution === 'recalled' ? 'Recalled' : 'Rejected'" />
                                            <span class="ui-hint">
                                                {{ $hop->resolution === 'recalled' ? 'by you' : 'by '.$hop->toSecretariat?->full_name }} {{ $hop->resolved_at?->format('d M Y H:i') }}@if ($hop->resolution_note) · {{ $hop->resolution_note }}@endif
                                            </span>
                                        @elseif ($hop->received_confirm)
                                            <x-ui.status-pill tone="success" label="Confirmed" />
                                            <span class="ui-hint">{{ $hop->confirmed_at?->format('d M Y H:i') }}</span>
                                        @else
                                            <x-ui.status-pill :tone="$workflow->agingTone($hop->created_at) ?? 'info'" label="Awaiting hardcopy" />
                                            <span class="ui-hint">{{ $waiting($hop->created_at) }}</span>
                                        @endif
                                    </td>
                                    <td class="actions">
                                        @if ($canForward && $hop->isAwaiting())
                                            <button type="button" class="btn btn-sm btn-ghost is-danger" x-data x-on:click.prevent="{{ $confirmRecall($hop->id, (string) $hop->letter?->sn_number) }}">
                                                <x-ui.icon name="undo-2" class="icon-sm" />
                                                Recall
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </x-ui.table>
                    </x-ui.card>
                @endforeach

                @if ($sent->isEmpty() && $sentSingles->isEmpty())
                    <x-ui.card>
                        @if ($sentFilter === 'overdue')
                            <x-ui.empty-state icon="circle-check" title="Nothing is overdue." description="Hand-overs waiting longer than {{ $workflow->alertDays() }} {{ \Illuminate\Support\Str::plural('day', $workflow->alertDays()) }} appear here." />
                        @else
                            <x-ui.empty-state icon="send" title="You have not sent any transmittals." description="Select several letters on Active Letters and use Dispatch selected." />
                        @endif
                    </x-ui.card>
                @endif

                @if ($sent && $sent->hasPages())
                    <div>{{ $sent->links() }}</div>
                @endif
            </div>
        @endif
    @endif
</div>
