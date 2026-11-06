<?php

namespace App\Services\Assets;

use App\Models\AuditLog;
use App\Models\IctAsset;
use App\Models\IctAssetModel;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * The one place an asset model is created, whether from the Asset Models settings screen or from the "Add new model"
 * panel on the Add Asset form: the same rules, the same duplicate check, the same audit row.
 */
class AssetModelService
{
    /** Every asset type code a model can belong to (its "category"). @return array<int, string> */
    public static function categoryOptions(): array
    {
        return collect(IctAsset::ASSET_TYPES)->flatMap(fn ($types) => array_keys($types))->values()->all();
    }

    public static function canManage(?User $user): bool
    {
        return $user !== null && ($user->hasRoles('super_admin') || $user->hasPermission('assets.manage_models'));
    }

    /**
     * Validation rules for a new model's name, category and manufacturer. $prefix is the property path the caller binds
     * them to ("" on the settings screen, "newModel." on the asset form). Names are unique across the whole catalogue.
     *
     * @return array<string, array<int, mixed>>
     */
    public function createRules(string $prefix = ''): array
    {
        return [
            $prefix.'name' => ['required', 'string', 'max:255', 'unique:ict_asset_models,name'],
            $prefix.'category' => ['required', Rule::in(self::categoryOptions())],
            $prefix.'manufacturer_id' => ['nullable', 'integer', $this->selectableManufacturerRule()],
        ];
    }

    /**
     * Only active manufacturers can be picked, except the one a model is already linked to: deactivating a
     * manufacturer shouldn't force its existing models off it the next time they're edited.
     */
    public function selectableManufacturerRule(?int $currentManufacturerId = null): Exists
    {
        return Rule::exists('ict_asset_manufacturers', 'id')->where(
            fn ($query) => $query
                ->where('is_active', true)
                ->when($currentManufacturerId, fn ($inner, $id) => $inner->orWhere('id', $id))
        );
    }

    /**
     * Create a model from already-validated data. The caller has checked the permission (the screens do it up front;
     * this refuses again so no other caller can skip it).
     *
     * @param  array{name: string, category: string, manufacturer_id?: int|string|null, notes?: string|null}  $data
     */
    public function create(array $data, ?string $imagePath = null): IctAssetModel
    {
        abort_unless(self::canManage(auth()->user()), 403, 'You do not have permission to manage models.');

        $model = IctAssetModel::create([
            'name' => trim($data['name']),
            'category' => $data['category'],
            'ict_asset_manufacturer_id' => ($data['manufacturer_id'] ?? null) ?: null,
            'notes' => $data['notes'] ?? null,
            'image_path' => $imagePath,
            'is_active' => true,
        ]);

        AuditLog::record('create_asset_model', Permission::MODULE_ASSETS, 'ict_asset_models', $model->id, null, $model->toArray());

        return $model;
    }
}
