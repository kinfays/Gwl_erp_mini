<div class="employee-leave-home">
    <x-ui.page-header title="Leave Home" :description="$employee->full_name.' · '.now()->format('F Y')">
        <x-slot:actions>
            <x-ui.button :href="route('leave.my-history')" icon="history">My History</x-ui.button>
            <x-ui.button :href="route('leave.apply')" variant="primary" icon="circle-plus">Apply for Leave</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="leave-home-grid">
        <div class="leave-balance-grid">
            @foreach ($balanceCards as $card)
                <section class="leave-balance-card" aria-labelledby="balance-{{ \Illuminate\Support\Str::slug($card['label']) }}">
                    <h2 id="balance-{{ \Illuminate\Support\Str::slug($card['label']) }}" class="leave-card-title">{{ $card['label'] }}</h2>

                    <div class="lb-main">
                        <div
                            class="leave-ring"
                            style="--used: {{ $card['used_percent'] }}%;"
                            role="img"
                            aria-label="{{ $card['used'] }} of {{ $card['total'] }} days used"
                        >
                            <span aria-hidden="true">{{ $card['used_percent'] }}%</span>
                        </div>

                        <div class="lb-figures">
                            <p class="lb-available"><strong>{{ $card['available'] }}</strong> {{ \Illuminate\Support\Str::plural('day', $card['available']) }} available</p>
                            <p class="lb-sub">Used {{ $card['used'] }} of {{ $card['total'] }}</p>
                        </div>
                    </div>
                </section>
            @endforeach
        </div>

        <x-ui.card title="Public Holidays" class="leave-holiday-panel" :padded="false">
            <div class="holiday-list">
                @forelse ($holidays as $holiday)
                    <div class="holiday-row">
                        <div class="holiday-date" aria-hidden="true">
                            <strong>{{ $holiday->holiday_date->format('d') }}</strong>
                            <span>{{ $holiday->holiday_date->format('M') }}</span>
                        </div>
                        <div class="holiday-meta">
                            <strong>{{ $holiday->holiday_name }}</strong>
                            <span>{{ $holiday->holiday_date->format('l, j F') }}</span>
                        </div>
                    </div>
                @empty
                    <x-ui.empty-state icon="calendar-days" title="No upcoming public holidays." />
                @endforelse
            </div>
        </x-ui.card>

        <x-ui.card title="My Applied Leave" :padded="false">
            <x-slot:actions>
                <a href="{{ route('leave.my-history') }}" class="btn btn-ghost btn-sm">View all <x-ui.icon name="arrow-right" class="icon-sm" /></a>
            </x-slot:actions>

            <div class="employee-list">
                @forelse ($recentRequests as $request)
                    <div class="leave-request-row">
                        <div>
                            <strong>{{ $request->start_date->format('d M') }} – {{ $request->end_date->format('d M Y') }}</strong>
                            <span>{{ $request->leave_type }}</span>
                        </div>
                        <x-ui.status-pill domain="leave" :status="$request->leave_status" />
                    </div>
                @empty
                    <x-ui.empty-state icon="calendar-days" title="No leave requests yet." description="Plan or apply for leave and it will show up here.">
                        <x-ui.button :href="route('leave.apply')" size="sm" variant="primary" icon="circle-plus">Apply for Leave</x-ui.button>
                    </x-ui.empty-state>
                @endforelse
            </div>
        </x-ui.card>

        <x-ui.card title="Team Leave" :padded="false">
            <div class="employee-list">
                @forelse ($teamLeave as $request)
                    @php
                        $teamState = $request->leave_status === 'Planned'
                            ? ['Planned', 'muted']
                            : ($request->start_date->isPast() ? ['On leave', 'lagoon'] : ['Upcoming', 'info']);
                    @endphp
                    <div class="team-leave-row">
                        <x-ui.avatar :name="$request->requester?->full_name ?? 'Team member'" :initials="$request->requester?->initials" />

                        <div class="team-person">
                            <strong>{{ $request->requester?->full_name ?? 'Team member' }}</strong>
                            <span>{{ $request->requester?->present_appointment ?? $request->requester?->unit ?? 'Team member' }}</span>
                        </div>

                        <div class="team-when">
                            <span class="nowrap">{{ $request->start_date->format('M d') }} – {{ $request->end_date->format('M d') }}</span>
                            <x-ui.status-pill :tone="$teamState[1]" :label="$teamState[0]" />
                        </div>
                    </div>
                @empty
                    <x-ui.empty-state icon="users" title="No upcoming team leave." />
                @endforelse
            </div>
        </x-ui.card>
    </div>
</div>
