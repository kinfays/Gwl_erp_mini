<section class="b-stats" aria-labelledby="stats-h">
    <h2 id="stats-h">{{ __('Stats') }}</h2>

    <dl class="b-stats-grid">
        <div><dt>{{ __('Views') }}</dt><dd>{{ number_format($post->view_count) }}</dd></div>
        <div><dt>{{ __('Readers') }}</dt><dd>{{ number_format($readers) }}</dd></div>
        <div><dt>{{ __('Likes') }}</dt><dd>{{ number_format($post->like_count) }}</dd></div>
        <div>
            <dt>{{ __('Views, last 14 days') }}</dt>
            <dd>@include('blog.partials.sparkline', ['values' => $series, 'label' => __('Views per day')])</dd>
        </div>
    </dl>

    <p class="b-stats-note">
        @if ($post->isPublished())
            {{ __('Views count each person once per article in a short while; you and the author are never counted. Readers are the different people who opened it.') }}
        @else
            {{ __('Nothing is counted until the article is published.') }}
        @endif
    </p>

    @if ($post->like_count > 0)
        <div class="b-likers">
            <button type="button" class="b-btn" wire:click="toggleLikers" aria-expanded="{{ $showLikers ? 'true' : 'false' }}" aria-controls="likers-list">
                {{ $showLikers ? __('Hide who liked it') : __('Who liked it') }}
            </button>

            @if ($likers)
                <div id="likers-list" class="b-table-wrap" style="margin-top:.75rem">
                    <table class="b-table">
                        <caption class="b-sr">{{ __('People who liked this article') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Name') }}</th>
                                <th scope="col">{{ __('District') }}</th>
                                <th scope="col">{{ __('Liked on') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($likers as $like)
                                @php($employee = $like->user?->employee ?? $like->user?->employeeByStaffId)
                                <tr wire:key="liker-{{ $like->user_id }}">
                                    <td>{{ $like->user?->full_name ?? '—' }}</td>
                                    <td>{{ $employee?->district?->district_name ?? '—' }}</td>
                                    <td style="white-space:nowrap">{{ $like->created_at?->format('j M Y, H:i') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($likers->hasPages())
                    <nav class="b-pager" aria-label="{{ __('Pages of likes') }}">
                        <button type="button" class="b-btn" wire:click="previousPage" @disabled($likers->onFirstPage())>← {{ __('Newer') }}</button>
                        <span>{{ __('Page :page of :last', ['page' => $likers->currentPage(), 'last' => $likers->lastPage()]) }}</span>
                        <button type="button" class="b-btn" wire:click="nextPage" @disabled(! $likers->hasMorePages())>{{ __('Older') }} →</button>
                    </nav>
                @endif
            @endif
        </div>
    @endif
</section>
