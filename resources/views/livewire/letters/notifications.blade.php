<div class="letter-notify" x-data x-on:click.outside="$wire.open && $wire.close()" wire:poll.{{ $pollSeconds }}s="pollForNewNotifications">
    <button
        type="button"
        class="icon-btn letter-notify-btn"
        wire:click="toggle"
        aria-haspopup="true"
        aria-expanded="{{ $open ? 'true' : 'false' }}"
        aria-label="Letters notifications{{ $unreadCount > 0 ? ', '.$unreadCount.' unread' : '' }}"
    >
        <x-ui.icon name="mail" />
        @if ($unreadCount > 0)
            <span class="count-badge" aria-hidden="true">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>
        @endif
    </button>

    @if ($open)
        <div class="letter-notify-menu" role="region" aria-label="Letter notifications">
            <div class="notify-head">
                <span>Letter notifications</span>
                @if ($unreadCount > 0)
                    <button type="button" wire:click="markAllRead">Mark all read</button>
                @endif
            </div>

            <div class="notify-tabs">
                <button type="button" wire:click="setTab('unread')" class="{{ $tab === 'unread' ? 'active' : '' }}" aria-pressed="{{ $tab === 'unread' ? 'true' : 'false' }}">Unread</button>
                <button type="button" wire:click="setTab('all')" class="{{ $tab === 'all' ? 'active' : '' }}" aria-pressed="{{ $tab === 'all' ? 'true' : 'false' }}">All</button>
            </div>

            <div wire:loading class="notify-loading">
                <span class="skeleton-line"></span>
                <span class="skeleton-line short"></span>
            </div>

            <div wire:loading.remove class="notify-list">
                @forelse ($notifications as $notification)
                    <button type="button" wire:click="openNotification({{ $notification->id }})" class="notify-row {{ $notification->is_read ? '' : 'unread' }}">
                        <span class="notify-dot" aria-hidden="true"></span>
                        <span>
                            <strong>{{ $notification->title }}</strong>
                            <small>{{ Str::limit($notification->message, 88) }}</small>
                            <em><span class="mono">{{ $notification->letter?->sn_number ?: ($notification->batch?->batch_no ?: 'Letter') }}</span> · {{ $notification->created_at?->diffForHumans() }}</em>
                        </span>
                    </button>
                @empty
                    <div class="notify-empty">No notifications.</div>
                @endforelse
            </div>
        </div>
    @endif
</div>
