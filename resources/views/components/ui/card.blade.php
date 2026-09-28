{{--
    Surface container. Title/description/actions render a header; `padded=false` lets a table or list
    sit edge to edge. Docs: docs/11-ui-components.md#card
--}}
@props([
    'title' => null,
    'description' => null,
    'padded' => true,
    'as' => 'section',
    'heading' => 'h2',
])

<{{ $as }} {{ $attributes->class(['ui-card']) }}>
    @if ($title || isset($actions))
        <header class="ui-card-head">
            <div class="ui-card-titles">
                @if ($title)
                    <{{ $heading }} class="ui-card-title">{{ $title }}</{{ $heading }}>
                @endif
                @if ($description)
                    <p class="ui-card-desc">{{ $description }}</p>
                @endif
            </div>

            @isset($actions)
                <div class="ui-card-actions">{{ $actions }}</div>
            @endisset
        </header>
    @endif

    <div @class(['ui-card-body', 'ui-card-body-flush' => ! $padded])>
        {{ $slot }}
    </div>

    @isset($footer)
        <footer class="ui-card-foot">{{ $footer }}</footer>
    @endisset
</{{ $as }}>
