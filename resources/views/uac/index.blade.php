<x-uac-layout>
    @php
        $viewer = auth()->user();
        $canManageRoles = $viewer->tier() >= \App\Models\User::TIER_GLOBAL_ADMIN;
        $canSeeAudit = $viewer->hasRoles('super_admin');
    @endphp

    <x-ui.page-header title="User Access Control" description="Accounts, roles and the audit trail for the portal." />

    <div class="ui-stat-grid dash-row">
        <x-ui.stat-tile label="Total Users" :value="$stats['users']" icon="users" :href="route('uac.users')" meta="Active directory visibility" />
        <x-ui.stat-tile label="Roles" :value="$stats['roles']" icon="key-round" tone="info" :href="$canManageRoles ? route('uac.roles') : null" meta="System and operational roles" />
        <x-ui.stat-tile label="Permissions" :value="$stats['permissions']" icon="shield-check" tone="lagoon" meta="Granular actions across modules" />
        <x-ui.stat-tile label="Audit Logs" :value="$stats['audit_logs']" icon="scroll-text" tone="muted" :href="$canSeeAudit ? route('uac.audit-log') : null" meta="Tracked security and user events" />
    </div>

    <div class="ui-grid ui-grid-2">
        <x-ui.card title="Recent Users" description="Latest accounts added to the platform." :padded="false">
            <x-slot:actions>
                <a href="{{ route('uac.users') }}" class="btn btn-ghost btn-sm">View all <x-ui.icon name="arrow-right" class="icon-sm" /></a>
            </x-slot:actions>

            <ul class="ui-list">
                @forelse ($recentUsers as $user)
                    @php($roleNames = $user->displayRoleNames('No additional role'))
                    <li>
                        <span class="ui-person">
                            <x-ui.avatar :name="$user->full_name ?? $user->name" />
                            <span>
                                <span class="ui-person-name">{{ $user->full_name ?? $user->name }}</span>
                                <span class="ui-person-sub">{{ $user->email }}</span>
                            </span>
                        </span>
                        <x-ui.badge :title="$roleNames">{{ $roleNames }}</x-ui.badge>
                    </li>
                @empty
                    <li class="ui-list-empty"><x-ui.empty-state icon="users" title="No users available." /></li>
                @endforelse
            </ul>
        </x-ui.card>

        <x-ui.card title="Recent Audit Activity" description="Latest tracked actions across protected modules." :padded="false">
            @if ($canSeeAudit)
                <x-slot:actions>
                    <a href="{{ route('uac.audit-log') }}" class="btn btn-ghost btn-sm">Open log <x-ui.icon name="arrow-right" class="icon-sm" /></a>
                </x-slot:actions>
            @endif

            <ul class="ui-list">
                @forelse ($recentLogs as $log)
                    <li>
                        <span>
                            <span class="ui-person-name">{{ $log->action }}</span>
                            <span class="ui-person-sub">{{ $log->actorLabelFor($viewer) }} • {{ $log->module ?: 'general' }}</span>
                        </span>
                        <span class="ui-hint nowrap">{{ $log->created_at?->diffForHumans() }}</span>
                    </li>
                @empty
                    <li class="ui-list-empty"><x-ui.empty-state icon="scroll-text" title="No audit logs found." /></li>
                @endforelse
            </ul>
        </x-ui.card>
    </div>
</x-uac-layout>
