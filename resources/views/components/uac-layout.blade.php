<x-erp-layout module="uac" title="User Access Control">
    <div class="content">
        @if (session('success'))
            <div x-data="{ show: true }"
                 x-init="setTimeout(() => show = false, 4000)"
                 x-show="show"
                 x-transition
                 class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800 relative">
                <p class="text-sm font-medium">{{ session('success') }}</p>
                <button type="button"
                        x-on:click="show = false"
                        class="absolute top-2 right-2 text-emerald-600 hover:text-emerald-800">
                    &times;
                </button>
            </div>
        @endif

        @if (session('error'))
            <div class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-red-800">
                <p class="text-sm font-medium">{{ session('error') }}</p>
            </div>
        @endif

        {{ $slot }}
    </div>
</x-erp-layout>
