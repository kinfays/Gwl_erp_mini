<x-erp-layout module="home" title="Home">
    @php
        $user = $user ?? auth()->user();
        $employee = $employee ?? ($user?->employee ?? $user?->employeeByStaffId);
        $dashboardModules = collect($modules ?? dashboardModules());
        $summary = $summary ?? [];
        $moduleIcons = [
            'leave' => 'calendar-days',
            'staff' => 'users',
            'letters' => 'mail',
            'visitors' => 'door-open',
            'assets' => 'monitor',
            'transport' => 'car',
            'credit_union' => 'landmark',
            'uac' => 'shield-check',
        ];
        $leave = $summary['leave'] ?? null;
        $approvals = $summary['approvals'] ?? null;
        $letters = $summary['letters'] ?? null;
        $visitors = $summary['visitors'] ?? null;
    @endphp

    <div class="content">
        <div class="page-head home-head">
            <div class="ph-left dashboard-greeting">
                <h1>
                    {{ $greeting ?? dashboardGreeting() }},
                    {{ $firstName ?? explode(' ', $employee?->full_name ?? 'User')[0] }}
                </h1>
                <p>
                    <span>{{ $today ?? now()->format('l, j F Y') }}</span>
                    <span>{{ $location ?? trim(($employee?->district?->district_name ?? '') . ', ' . ($employee?->region?->region_name ?? ''), ', ') }}</span>
                    <span>{{ $roleName ?? $user?->displayRoleNames() }}</span>
                </p>
            </div>
            <div class="ph-right">
                <x-ui.button :href="route('leave.apply')" variant="primary" icon="circle-plus">{{ __('Apply for leave') }}</x-ui.button>
            </div>
        </div>

        @if ($leave || $approvals || $letters || $visitors)
            <section aria-labelledby="home-summary-title" class="home-summary">
                <h2 id="home-summary-title" class="sr-only-text">{{ __('Your summary') }}</h2>
                <div class="ui-stat-grid">
                    @if ($leave)
                        <x-ui.stat-tile
                            :href="route('leave.home')"
                            icon="calendar-days"
                            :label="__('Annual leave left')"
                            :value="$leave['remaining'].' '.\Illuminate\Support\Str::plural('day', $leave['remaining'])"
                            :meta="__('of :total days in :year · :used used', ['total' => $leave['total'], 'year' => $leave['year'], 'used' => $leave['used']])"
                        />
                    @endif

                    @if ($approvals)
                        <x-ui.stat-tile
                            :href="route('leave.approvals')"
                            icon="square-check-big"
                            :tone="$approvals['count'] > 0 ? 'warning' : 'muted'"
                            :label="$approvals['actionable'] ? __('Awaiting your decision') : __('Pending approvals in your zone')"
                            :value="$approvals['count']"
                            :meta="$approvals['count'] > 0 ? __('Leave requests') : __('Nothing waiting')"
                        />
                    @endif

                    @if ($letters)
                        <x-ui.stat-tile
                            :href="route('letters.home')"
                            icon="mail"
                            tone="info"
                            :label="__('Unread letters')"
                            :value="$letters['unread']"
                            :meta="$letters['unread'] > 0 ? __('In your letters inbox') : __('All caught up')"
                        />
                    @endif

                    @if ($visitors)
                        <x-ui.stat-tile
                            :href="route('visitors.home')"
                            icon="door-open"
                            tone="lagoon"
                            :label="__('Visitors today')"
                            :value="$visitors['today']"
                            :meta="__(':count on site now', ['count' => $visitors['on_site']])"
                        />
                    @endif
                </div>
            </section>
        @endif

        <section aria-labelledby="home-modules-title">
            <h2 id="home-modules-title" class="section-title">{{ __('Your modules') }}</h2>

            <div class="dashboard-grid-loaded">
                @foreach ($dashboardModules as $moduleCard)
                    @php
                        $slug = $moduleCard['slug'] ?? '';
                    @endphp
                    <a href="{{ $moduleCard['route'] }}" class="dashboard-card">
                        <div class="dashboard-card-top">
                            <span class="dashboard-module-icon" aria-hidden="true">
                                <x-ui.icon :name="$moduleIcons[$slug] ?? 'layout-dashboard'" class="icon-lg" />
                            </span>
                            @if ($slug === 'leave')
                                <span class="dashboard-badge">{{ __('Everyone') }}</span>
                            @endif
                        </div>

                        <h3>{{ $moduleCard['title'] }}</h3>
                        <p>{{ $moduleCard['description'] }}</p>
                        <span class="dashboard-card-go" aria-hidden="true">
                            {{ __('Open') }}
                            <x-ui.icon name="arrow-right" class="icon-sm" />
                        </span>
                    </a>
                @endforeach
            </div>
        </section>
    </div>
</x-erp-layout>
