<x-erp-layout module="blog" title="Manage posts">
    <div class="content">
        @if (session('blog_notice'))
            <x-ui.alert tone="success" role="status" class="dash-row">{{ session('blog_notice') }}</x-ui.alert>
        @endif

        <livewire:blog.manage-posts />
    </div>
</x-erp-layout>
