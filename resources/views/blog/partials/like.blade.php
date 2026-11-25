{{--
    The heart button with its count (resources/js/blog-like.js). $post, $liked (does the viewer like it), $variant ('compact' on cards
    and rows: heart and number; 'full' on the article page: also the "You and 12 others" line), $live (the ONE summary line of a page
    that announces changes to screen readers). Rendered complete on the server, so it reads right before (and without) the script.
--}}
@php
    $variant = $variant ?? 'compact';
    $live = $live ?? false;
    $likes = (int) $post->like_count;
    $summary = $liked
        ? ($likes <= 1 ? __('You like this') : __('You and :others', ['others' => trans_choice(':count other|:count others', $likes - 1)]))
        : ($likes === 0 ? __('Be the first to like this') : trans_choice(':count like|:count likes', $likes));
@endphp
<span class="b-like b-like-{{ $variant }}" x-data="blogLike"
      data-id="{{ $post->id }}" data-liked="{{ $liked ? '1' : '0' }}" data-count="{{ $likes }}" data-like-url="{{ route('blog.like', $post) }}"
      x-on:blog-like-changed.window="sync($event.detail)">
    <button type="button" class="b-like-btn"
            aria-pressed="{{ $liked ? 'true' : 'false' }}"
            aria-label="{{ trans_choice('Like this article, :count like|Like this article, :count likes', $likes) }}"
            x-bind:aria-pressed="liked ? 'true' : 'false'"
            x-bind:aria-label="label()"
            x-bind:aria-busy="busy ? 'true' : 'false'"
            x-on:click="toggle()">
        <svg class="b-like-ico" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
        <span class="b-like-n" aria-hidden="true" x-text="count">{{ $likes }}</span>
    </button>
    @if ($variant === 'full')
        <span class="b-like-sum" @if ($live) aria-live="polite" @endif x-text="summary()">{{ $summary }}</span>
    @endif
</span>
