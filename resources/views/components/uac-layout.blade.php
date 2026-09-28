<x-erp-layout module="uac" title="User Access Control">
    <div class="content">
        @if (session('success'))
            <div x-data="{ show: true }" x-init="setTimeout(() => show = false, 6000)" x-show="show" x-transition.opacity>
                <x-ui.alert tone="success" role="status">
                    {{ session('success') }}
                    <x-slot:actions>
                        <button type="button" class="icon-btn alert-close" x-on:click="show = false" aria-label="Dismiss message">
                            <x-ui.icon name="x" class="icon-sm" />
                        </button>
                    </x-slot:actions>
                </x-ui.alert>
            </div>
        @endif

        @if (session('error'))
            <x-ui.alert tone="danger" role="alert">{{ session('error') }}</x-ui.alert>
        @endif

        {{ $slot }}
    </div>
</x-erp-layout>
