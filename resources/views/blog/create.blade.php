<x-blog-layout title="New article" active="manage" :region-name="$regionName" :wide="true">
    <div class="b-wrap b-wrap-wide">
        <livewire:blog.post-form />
    </div>

    @push('scripts')
        @vite(['resources/js/blog-editor.js', 'resources/js/blog-lightbox.js'])
    @endpush
</x-blog-layout>
