@props(['messages'])

@if ($messages)
    <ul {{ $attributes->merge(['class' => 'ui-error-list']) }}>
        @foreach ((array) $messages as $message)
            <li class="ui-error">
                <x-ui.icon name="circle-alert" class="icon-sm" />
                <span>{{ $message }}</span>
            </li>
        @endforeach
    </ul>
@endif
