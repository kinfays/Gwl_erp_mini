<div class="employee-leave-home">
    <div class="page-head">
        <div class="ph-left">
            <h2>Leave Home</h2>
            <p>{{ $employee->full_name }} &middot; {{ now()->format('F Y') }}</p>
        </div>

        <div class="ph-right">
            <a href="{{ route('leave.my-history') }}" class="btn">My History</a>
            <a href="{{ route('leave.apply') }}" class="btn btn-primary">Apply for Leave</a>
        </div>
    </div>

    <div class="employee-home-body">
        <div class="leave-home-grid">
            <div class="leave-balance-grid">
                @foreach($balanceCards as $card)
                    <div class="leave-balance-card">
                        <div class="leave-card-title">{{ $card['label'] }}</div>

                        <div class="leave-balance-main">
                            <div
                                class="leave-ring"
                                style="--used: {{ $card['used_percent'] }}%;"
                                aria-label="{{ $card['used'] }} of {{ $card['total'] }} days used"
                            >
                                <span>Total<br>{{ $card['total'] }}</span>
                            </div>

                            <div class="leave-card-numbers">
                                <strong>{{ $card['available'] }}</strong>
                                <span>Available</span>
                                <strong>{{ $card['used'] }}</strong>
                                <span>Used</span>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="pg leave-holiday-panel">
                <div class="pg-head">
                    <span class="pg-title">Public Holidays</span>
                </div>

                <div class="holiday-list">
                    @forelse($holidays as $holiday)
                        <div class="holiday-row">
                            <div class="holiday-date">
                                <strong>{{ $holiday->holiday_date->format('d') }}</strong>
                                <span>{{ $holiday->holiday_date->format('M') }}</span>
                            </div>
                            <div class="holiday-meta">
                                <strong>{{ $holiday->holiday_name }}</strong>
                                <span>{{ $holiday->holiday_date->format('l') }}</span>
                            </div>
                        </div>
                    @empty
                        <div class="empty-state">No upcoming public holidays.</div>
                    @endforelse
                </div>
            </div>

            <div class="pg">
                <div class="pg-head">
                    <span class="pg-title">My Applied Leave</span>
                    <a href="{{ route('leave.my-history') }}" class="actn">View all</a>
                </div>

                <div class="employee-list">
                    @forelse($recentRequests as $request)
                        <div class="leave-request-row">
                            <div>
                                <strong>{{ $request->start_date->format('d M') }} - {{ $request->end_date->format('d M y') }}</strong>
                                <span>{{ $request->leave_type }}</span>
                            </div>
                            <span class="leave-status {{ \Illuminate\Support\Str::slug($request->leave_status) }}">
                                {{ $request->leave_status }}
                            </span>
                        </div>
                    @empty
                        <div class="empty-state">No leave requests yet.</div>
                    @endforelse
                </div>
            </div>

            <div class="pg">
                <div class="pg-head">
                    <span class="pg-title">Team Leave</span>
                </div>

                <div class="employee-list">
                    @forelse($teamLeave as $request)
                        <div class="team-leave-row">
                            <div class="team-avatar" aria-hidden="true">
                                {{ $request->requester?->initials ?? 'TM' }}
                            </div>

                            <div class="team-person">
                                <strong>{{ $request->requester?->full_name ?? 'Team member' }}</strong>
                                <span>{{ $request->requester?->present_appointment ?? $request->requester?->unit ?? 'Team member' }}</span>
                            </div>

                            <div class="team-dates {{ \Illuminate\Support\Str::slug($request->leave_status) }}">
                                <strong>{{ $request->start_date->format('M d') }} - {{ $request->end_date->format('M d') }}</strong>
                                <span>
                                    @if($request->leave_status === 'Planned')
                                        Planned
                                    @else
                                        {{ $request->start_date->isPast() ? 'On leave' : 'Upcoming' }}
                                    @endif
                                </span>
                            </div>
                        </div>
                    @empty
                        <div class="empty-state">No upcoming team leave.</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
