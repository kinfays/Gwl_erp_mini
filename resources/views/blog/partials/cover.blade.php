{{-- A cover as a card shows it. $post, $ratio (b-r169 | b-r43), $sizes, $eager (the first picture on a page is not lazy). --}}
@php
    $dims = $post->coverDimensions('medium');
    $srcset = $post->coverSrcset();
@endphp
<div class="b-frame {{ $ratio ?? 'b-r169' }}">
    @if ($post->hasCover())
        <img
            src="{{ $post->coverUrl('medium') }}"
            @if ($srcset) srcset="{{ $srcset }}" sizes="{{ $sizes ?? '(min-width: 900px) 40vw, 100vw' }}" @endif
            @if ($dims) width="{{ $dims[0] }}" height="{{ $dims[1] }}" @endif
            alt=""
            loading="{{ ($eager ?? false) ? 'eager' : 'lazy' }}"
            decoding="async"
            style="object-position: {{ $post->coverFocus() }}"
        >
    @else
        <div class="b-ph b-ph-{{ $post->category }}" aria-hidden="true">{{ \Illuminate\Support\Str::substr($post->categoryLabel(), 0, 1) }}</div>
    @endif
</div>
