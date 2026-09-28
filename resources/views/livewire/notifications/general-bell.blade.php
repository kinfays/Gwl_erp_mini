<div class="general-notify" x-data x-on:click.outside="$wire.open && $wire.close()" wire:poll.{{ $pollSeconds }}s="pollForNewNotifications">
    <button
        type="button"
        class="icon-btn general-notify-btn"
        wire:click="toggle"
        aria-haspopup="true"
        aria-expanded="{{ $open ? 'true' : 'false' }}"
        aria-label="General notifications{{ $unreadCount > 0 ? ', '.$unreadCount.' unread' : '' }}"
    >
        <x-ui.icon name="bell" />
        @if ($unreadCount > 0)
            <span class="count-badge" aria-hidden="true">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>
        @endif
    </button>

    @if ($open)
        <div class="notify-menu" role="region" aria-label="Notifications">
            <div class="notify-head">
                <span>Notifications</span>
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
