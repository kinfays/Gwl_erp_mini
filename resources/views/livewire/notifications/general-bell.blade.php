<div class="general-notify" x-data x-on:click.outside="$wire.open && $wire.close()" wire:poll.{{ $pollSeconds }}s="pollForNewNotifications">
    <button type="button" class="tb-icon-btn general-notify-btn" wire:click="toggle" aria-label="General notifications">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 1 0-12 0v3.2a2 2 0 0 1-.6 1.4L4 17h5" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M10 21h4" />
        </svg>
        @if ($unreadCount > 0)
            <span class="notify-count">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>
        @endif
    </button>

    @if ($open)
        <div class="notify-menu">
            <div class="notify-head">
                <span>Notifications</span>
                @if ($unreadCount > 0)
                    <button type="button" wire:click="markAllRead">Mark all read</button>
                @endif
            </div>

            <div class="notify-tabs">
                <button type="button" wire:click="setTab('unread')" class="{{ $tab === 'unread' ? 'active' : '' }}">Unread</button>
                <button type="button" wire:click="setTab('all')" class="{{ $tab === 'all' ? 'active' : '' }}">All</button>
            </div>

            <div wire:loading class="notify-loading">
                <span class="skeleton-line"></span>
                <span class="skeleton-line short"></span>
            </div>

            <div wire:loading.remove>
                @forelse ($notifications as $notification)
                    @php
                        $data = $notification->data ?? [];
                        $title = $data['title'] ?? class_basename($notification->type);
                        $message = $data['message'] ?? '';
                    @endphp
                    <button type="button" wire:click="openNotification('{{ $notification->id }}')" class="notify-row {{ $notification->read_at ? '' : 'unread' }}">
                        <span class="notify-dot" aria-hidden="true"></span>
                        <span>
                            <strong>{{ $title }}</strong>
                            @if ($message)
                                <small>{{ Str::limit($message, 86) }}</small>
                            @endif
                            <em>{{ $notification->created_at?->diffForHumans() }}</em>
                        </span>
                    </button>
                @empty
                    <div class="notify-empty">No notifications.</div>
                @endforelse
            </div>
        </div>
    @endif
</div>
