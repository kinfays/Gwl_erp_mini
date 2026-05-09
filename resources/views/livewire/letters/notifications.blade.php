<div class="letter-notify" x-data x-on:click.outside="$wire.open && $wire.close()" wire:poll.{{ $pollSeconds }}s="pollForNewNotifications">
    <button type="button" class="tb-icon-btn letter-notify-btn" wire:click="toggle" aria-label="Letters notifications">
        <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
            <path d="M2 4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V4Zm2-.5a.5.5 0 0 0-.5.5v.4L8 7.3l4.5-2.9V4a.5.5 0 0 0-.5-.5H4Zm8.5 2.7L8.4 8.8a.75.75 0 0 1-.8 0L3.5 6.2V12a.5.5 0 0 0 .5.5h8a.5.5 0 0 0 .5-.5V6.2Z"/>
        </svg>
        @if ($unreadCount > 0)
            <span class="notify-count">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>
        @endif
    </button>

    @if ($open)
        <div class="letter-notify-menu">
            <div class="notify-head">
                <span>Letter notifications</span>
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
                    <button type="button" wire:click="openNotification({{ $notification->id }})" class="notify-row {{ $notification->is_read ? '' : 'unread' }}">
                        <span class="notify-dot" aria-hidden="true"></span>
                        <span>
                            <strong>{{ $notification->title }}</strong>
                            <small>{{ Str::limit($notification->message, 88) }}</small>
                            <em>{{ $notification->letter?->sn_number ?: 'Letter' }} - {{ $notification->created_at?->diffForHumans() }}</em>
                        </span>
                    </button>
                @empty
                    <div class="notify-empty">No notifications.</div>
                @endforelse
            </div>
        </div>
    @endif
</div>
