<x-erp-layout module="blog" title="Regional blog">
    <div class="content">
        @if (session('blog_notice'))
            <x-ui.alert tone="success" role="status" class="dash-row">{{ session('blog_notice') }}</x-ui.alert>
        @endif

        <livewire:blog.feed />
    </div>
</x-erp-layout>
