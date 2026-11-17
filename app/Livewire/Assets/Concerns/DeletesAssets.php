<?php

namespace App\Livewire\Assets\Concerns;

use App\Models\IctAsset;
use App\Services\Assets\AssetRecordService;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Locked;

/**
 * The Delete button and its confirmation on the Assets, Phones and Network lists. Only the choreography lives here:
 * who may delete, what is refused and the audit row are all AssetRecordService::delete(). The asset is resolved
 * through scopeAssetsForActor() (the WRITE scope, not the viewing one), so a device the person can only see answers
 * 404 here exactly as it does for Edit. Needs a CATEGORY constant, ScopesAssetsByActor and WithPagination.
 */
trait DeletesAssets
{
    /** The asset the confirmation is open for. Locked, and resolved again inside the write scope on every call. */
    #[Locked]
    public ?int $deletingAssetId = null;

    public function confirmDelete(int $assetId): void
    {
        $this->authorizeDelete();

        $asset = $this->deletableAsset($assetId);

        $this->resetErrorBag('delete');
        $this->deletingAssetId = $asset->id;
    }

    public function cancelDelete(): void
    {
        $this->deletingAssetId = null;
        $this->resetErrorBag('delete');
    }

    public function deleteAsset(): void
    {
        $this->authorizeDelete();
        abort_unless($this->deletingAssetId, 404);

        $asset = $this->deletableAsset($this->deletingAssetId);

        try {
            // A refusal (assigned, MDM link, history) is a ValidationException on `delete`: Livewire shows it in the dialog.
            app(AssetRecordService::class)->delete($asset, $this->actor());
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        $this->dispatch('toast', type: 'success', message: 'Asset deleted.');
        $this->cancelDelete();
        $this->resetPage();
    }

    protected function authorizeDelete(): void
    {
        abort_unless(AssetRecordService::mayDelete($this->actor()), 403, 'You do not have permission to delete assets.');
    }

    protected function deletableAsset(int $assetId): IctAsset
    {
        return $this->scopeAssetsForActor(IctAsset::query())
            ->where('device_category', self::CATEGORY)
            ->findOrFail($assetId);
    }

    /** @return array<string, mixed> */
    protected function deleteViewData(): array
    {
        return [
            'canDelete' => AssetRecordService::mayDelete($this->actor()),
            'deletingName' => $this->deletingAssetId
                ? (string) IctAsset::query()->whereKey($this->deletingAssetId)->value('asset_name')
                : '',
        ];
    }
}
