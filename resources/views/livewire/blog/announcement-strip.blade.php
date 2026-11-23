<div>
    @if ($announcements->isNotEmpty())
        <section class="b-annstrip" aria-label="{{ __('Announcements') }}">
            @foreach ($announcements as $announcement)
                <div class="b-annstrip-item" wire:key="strip-{{ $announcement->id }}">
                    <span class="b-annstrip-label">{{ __('Announcement') }}</span>
                    <a href="{{ $announcement->url() }}">{{ $announcement->title }}</a>
                    @if ($announcement->announce_until)
                        <span class="b-annstrip-until">{{ __('until :date', ['date' => $announcement->announce_until->format('j M')]) }}</span>
                    @endif
                    <button type="button" class="b-annstrip-x" wire:click="dismiss({{ $announcement->id }})" aria-label="{{ __('Dismiss: :title', ['title' => $announcement->title]) }}">
                        <span aria-hidden="true">×</span>
                    </button>
                </div>
            @endforeach
        </section>
    @endif
</div>
