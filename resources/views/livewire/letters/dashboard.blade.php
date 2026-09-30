<div>
    <x-ui.page-header title="Letters Dashboard" :description="now()->format('F Y').' correspondence overview'">
        <x-slot:actions>
            <a href="{{ route('letters.active') }}" class="btn btn-secondary">
                <x-ui.icon name="inbox" />
                Active Letters
            </a>
            @if (auth()->user()?->hasRoles('super_admin') || auth()->user()?->hasPermission('letters.create'))
                <a href="{{ route('letters.create') }}" class="btn btn-primary">
                    <x-ui.icon name="file-plus" />
                    New Letter
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if ($missingEmployee)
        <x-ui.alert tone="danger">Your user account is not linked to an employee record, so letters cannot be assigned to you.</x-ui.alert>
    @else
        <div class="ui-stat-grid dash-row">
            <x-ui.stat-tile label="Total" :value="$stats['total']" icon="mail" meta="Letters visible to you" />
            <x-ui.stat-tile label="Pending Review" :value="$stats['pending']" icon="hourglass" tone="warning" meta="Received or in review at your desk" />
            <x-ui.stat-tile label="Dispatch" :value="$stats['dispatched']" icon="send" tone="lagoon" meta="Dispatched, not yet closed" />
            <x-ui.stat-tile label="Closed" :value="$stats['closed']" icon="archive" tone="muted" meta="Closed at your desk" :href="route('letters.closed')" />
            <x-ui.stat-tile
                :label="'Unconfirmed > '.$alertDays.' '.\Illuminate\Support\Str::plural('day', $alertDays)"
                :value="$unconfirmedOverdue"
                icon="clock"
                tone="warning"
                meta="Hand-overs you sent that nobody has confirmed"
                :href="route('letters.transmittals', ['tab' => 'sent', 'filter' => 'overdue'])"
            />
        </div>

        <x-ui.card title="Requires your attention" description="Letters received or in review at your desk" :padded="false">
            <x-slot:actions>
                <a href="{{ route('letters.active') }}" class="btn btn-ghost btn-sm">
                    View all
                    <x-ui.icon name="arrow-right" class="icon-sm" />
                </a>
            </x-slot:actions>

            <x-ui.table label="Letters requiring your attention" :sticky="false">
                <x-slot:head>
                    <tr>
                        <th>SN#</th>
                        <th>Subject</th>
                        <th>Sender</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </x-slot:head>

                @forelse ($attentionLetters as $letter)
                    @php($status = $letter->latestStatusFor(auth()->user()->employee ?? auth()->user()->employeeByStaffId)?->status ?? 'Received')
                    <tr wire:key="attention-{{ $letter->id }}">
                        <td class="mono nowrap">{{ $letter->sn_number }}</td>
                        <td>
                            <span class="ui-cell-stack">
                                <a href="{{ route('letters.active', ['letter' => $letter->id]) }}" class="row-link">{{ $letter->subject }}</a>
                                <span class="ui-person-sub">{{ $letter->ref_no ?: 'No reference' }}</span>
                            </span>
                        </td>
                        <td>{{ $letter->sender_name }}</td>
                        <td><x-ui.status-pill domain="letter" :status="$status" /></td>
                        <td class="nowrap cell-muted">{{ $letter->date_on_letter?->format('d M Y') }}</td>
                    </tr>
                @empty
                    <x-ui.empty-row :colspan="5" icon="inbox" title="No letters need your attention." description="New letters routed to you will show up here." />
                @endforelse
            </x-ui.table>
        </x-ui.card>
    @endif
</div>
