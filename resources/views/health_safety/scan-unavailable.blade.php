<x-erp-layout module="health_safety" title="Not available">
    <div class="content">
        <x-ui.card title="This item is not available to you">
            <p>The code you scanned does not lead to anything you may open. If you think it should, ask your Health &amp; Safety officer.</p>
            <div class="ui-form-actions">
                <x-ui.button :href="route('health_safety.home')" variant="primary">Go to Health &amp; Safety</x-ui.button>
            </div>
        </x-ui.card>
    </div>
</x-erp-layout>
