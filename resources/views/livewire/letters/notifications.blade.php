<div class="letter-notify" wire:poll.90s>
    <button type="button" class="tb-back letter-notify-btn" wire:click="toggle" aria-label="Letters notifications">
        <svg width="13" height="13" viewBox="0 0 16 16" fill="currentColor"><path d="M2 4a2 2 0 012-2h8a2 2 0 012 2v8a2 2 0 01-2 2H4a2 2 0 01-2-2V4zm2-.5a.5.5 0 00-.5.5v.4L8 7.3l4.5-2.9V4a.5.5 0 00-.5-.5H4zm8.5 2.7L8.4 8.8a.75.75 0 01-.8 0L3.5 6.2V12a.5.5 0 00.5.5h8a.5.5 0 00.5-.5V6.2z"/></svg>
        @if ($unreadCount > 0)
            <span class="letter-notify-count">{{ $unreadCount }}</span>
        @endif
    </button>

    @if ($open)
        <div class="letter-notify-menu">
            <div class="letter-notify-head">Letter notifications</div>
            @forelse ($notifications as $notification)
                <button type="button" wire:click="openNotification({{ $notification->id }})" class="letter-notify-row {{ $notification->is_read ? '' : 'unread' }}">
                    <span>{{ $notification->title }}</span>
                    <small>{{ $notification->letter?->sn_number }} · {{ $notification->created_at?->diffForHumans() }}</small>
                </button>
            @empty
                <div class="letter-notify-empty">No notifications.</div>
            @endforelse
        </div>
    @endif
</div>
