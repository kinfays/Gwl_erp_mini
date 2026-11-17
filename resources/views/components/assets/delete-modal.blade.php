{{--
    Confirmation for "Delete asset" on the Assets, Phones and Network lists. Rendered inside the list's
    @if ($deletingAssetId) block. The Livewire component (trait DeletesAssets) supplies cancelDelete() and deleteAsset();
    a refusal from AssetRecordService::delete() arrives as the `delete` error and stays here, so the person reads why.
--}}
@props(['name' => ''])

<x-ui.modal title="Delete asset" close="cancelDelete()" size="sm" icon="trash-2" tone="danger">
    <p>Delete <strong>{{ $name }}</strong>? This cannot be undone.</p>
    <p class="ui-hint">An asset that is assigned, linked to a managed phone or has any history cannot be deleted. Set its status to Retired instead.</p>

    @error('delete')
        <x-ui.alert tone="danger" role="alert">{{ $message }}</x-ui.alert>
    @enderror

    <x-slot:footer>
        <button type="button" class="btn btn-ghost" wire:click="cancelDelete">Cancel</button>
        <button type="button" class="btn btn-danger-solid" wire:click="deleteAsset" wire:loading.attr="disabled" wire:target="deleteAsset">Delete asset</button>
    </x-slot:footer>
</x-ui.modal>
