<?php

namespace App\Services\Transport;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use App\Models\VehicleModel;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The ONLY place a vehicle brand or model is created, renamed, moved or (de)activated: the "Add new brand / model"
 * panels on the vehicle form, the vehicle import and the Transport > Settings screens all come through here, so the
 * duplicate rule (case-insensitive; a model name is unique within its brand) and the audit row are written once.
 *
 * vehicles.brand / vehicles.model stay plain text copied from the catalogue. A rename or a move therefore rewrites the
 * matching vehicles' text in the SAME transaction (soft-deleted vehicles included, matched case-insensitively; a model
 * also by its old brand). Nothing is ever deleted: a brand or model is deactivated, because vehicles refer to the name.
 *
 * The catalogue is shared and not region-scoped; the only things this class reports about vehicles are counts.
 */
class VehicleCatalogService
{
    public const PERMISSION = 'transport.manage_vehicle_catalog';

    /** Who may change the catalogue (the Settings screens and the Add-new panels on the vehicle form). */
    public static function canManage(?User $user): bool
    {
        return $user !== null && app(TransportScope::class)->can($user, self::PERMISSION);
    }

    /** @return array<int, mixed> */
    public function brandNameRules(?int $ignoreBrandId = null): array
    {
        return [
            'required', 'string', 'max:255',
            function (string $attribute, mixed $value, Closure $fail) use ($ignoreBrandId): void {
                if ($this->findBrand((string) $value, $ignoreBrandId)) {
                    $fail('This brand already exists.');
                }
            },
        ];
    }

    /** @return array<int, mixed> */
    public function modelNameRules(int $brandId, ?int $ignoreModelId = null): array
    {
        return [
            'required', 'string', 'max:255',
            function (string $attribute, mixed $value, Closure $fail) use ($brandId, $ignoreModelId): void {
                if ($this->findModel($brandId, (string) $value, $ignoreModelId)) {
                    $fail('This brand already has that model.');
                }
            },
        ];
    }

    public function createBrand(string $name): VehicleBrand
    {
        $this->authorize();

        $brand = VehicleBrand::create(['name' => trim($name), 'is_active' => true]);

        AuditLog::record('create_vehicle_brand', Permission::MODULE_TRANSPORT, 'vehicle_brands', $brand->id, null, $brand->toArray());

        return $brand;
    }

    public function createModel(VehicleBrand $brand, string $name): VehicleModel
    {
        $this->authorize();

        $model = VehicleModel::create(['vehicle_brand_id' => $brand->id, 'name' => trim($name), 'is_active' => true]);

        AuditLog::record('create_vehicle_model', Permission::MODULE_TRANSPORT, 'vehicle_models', $model->id, null, $model->toArray());

        return $model;
    }

    /**
     * Rename a brand. Every vehicle whose brand text matches the old name is rewritten in the same transaction.
     *
     * @return int how many vehicles were rewritten
     */
    public function updateBrand(VehicleBrand $brand, string $name): int
    {
        $this->authorize();
        $name = trim($name);

        return DB::transaction(function () use ($brand, $name): int {
            $brand = VehicleBrand::query()->lockForUpdate()->findOrFail($brand->id);
            $this->assertBrandNameFree($name, $brand->id);

            $old = $brand->toArray();
            $oldName = $brand->name;

            if ($oldName === $name) {
                return 0;
            }

            $vehicles = $this->vehiclesWithBrand($oldName)->update(['brand' => $name, 'updated_at' => now()]);
            $brand->update(['name' => $name]);

            AuditLog::record('update_vehicle_brand', Permission::MODULE_TRANSPORT, 'vehicle_brands', $brand->id, $old, $brand->fresh()->toArray() + ['vehicles_updated' => $vehicles]);

            return $vehicles;
        });
    }

    /**
     * Rename a model and/or move it to another brand. The vehicles that carry the old brand AND the old model text are
     * rewritten to the new names in the same transaction.
     *
     * @return int how many vehicles were rewritten
     */
    public function updateModel(VehicleModel $model, string $name, ?int $brandId = null): int
    {
        $this->authorize();
        $name = trim($name);

        return DB::transaction(function () use ($model, $name, $brandId): int {
            $model = VehicleModel::query()->lockForUpdate()->findOrFail($model->id);
            $oldBrand = VehicleBrand::query()->findOrFail($model->vehicle_brand_id);
            $newBrand = $brandId && $brandId !== $oldBrand->id
                ? VehicleBrand::query()->lockForUpdate()->findOrFail($brandId)
                : $oldBrand;

            $this->assertModelNameFree($newBrand->id, $name, $model->id);

            $old = $model->toArray();

            if ($newBrand->id === $oldBrand->id && $model->name === $name) {
                return 0;
            }

            $vehicles = $this->vehiclesWithModel($oldBrand->name, $model->name)
                ->update(['brand' => $newBrand->name, 'model' => $name, 'updated_at' => now()]);

            $model->update(['vehicle_brand_id' => $newBrand->id, 'name' => $name]);

            AuditLog::record(
                'update_vehicle_model',
                Permission::MODULE_TRANSPORT,
                'vehicle_models',
                $model->id,
                $old + ['brand_name' => $oldBrand->name],
                $model->fresh()->toArray() + ['brand_name' => $newBrand->name, 'vehicles_updated' => $vehicles],
            );

            return $vehicles;
        });
    }

    /** Deactivating never touches vehicles: the vehicle form keeps a vehicle's current brand/model selectable. */
    public function setBrandActive(VehicleBrand $brand, bool $active): void
    {
        $this->authorize();

        DB::transaction(function () use ($brand, $active): void {
            $brand = VehicleBrand::query()->lockForUpdate()->findOrFail($brand->id);

            if ($brand->is_active === $active) {
                return;
            }

            $old = $brand->toArray();
            $brand->update(['is_active' => $active]);

            AuditLog::record($active ? 'activate_vehicle_brand' : 'deactivate_vehicle_brand', Permission::MODULE_TRANSPORT, 'vehicle_brands', $brand->id, $old, $brand->fresh()->toArray());
        });
    }

    public function setModelActive(VehicleModel $model, bool $active): void
    {
        $this->authorize();

        DB::transaction(function () use ($model, $active): void {
            $model = VehicleModel::query()->lockForUpdate()->findOrFail($model->id);

            if ($model->is_active === $active) {
                return;
            }

            $old = $model->toArray();
            $model->update(['is_active' => $active]);

            AuditLog::record($active ? 'activate_vehicle_model' : 'deactivate_vehicle_model', Permission::MODULE_TRANSPORT, 'vehicle_models', $model->id, $old, $model->fresh()->toArray());
        });
    }

    /** How many vehicles (soft-deleted included) carry this brand's text: what a rename would rewrite. */
    public function vehicleCountForBrand(VehicleBrand $brand): int
    {
        return $this->vehiclesWithBrand($brand->name)->count();
    }

    /** How many vehicles carry this model's text under this brand: what a rename or a move would rewrite. */
    public function vehicleCountForModel(VehicleModel $model): int
    {
        $brand = $model->relationLoaded('brand') ? $model->brand : VehicleBrand::query()->find($model->vehicle_brand_id);

        return $brand ? $this->vehiclesWithModel($brand->name, $model->name)->count() : 0;
    }

    /**
     * Vehicles per brand text, for the Brands list (one query). Keyed by lower-cased, trimmed name.
     *
     * @return array<string, int>
     */
    public function brandVehicleCounts(): array
    {
        return Vehicle::withTrashed()
            ->selectRaw('LOWER(TRIM(brand)) as k, COUNT(*) as c')
            ->groupBy(DB::raw('LOWER(TRIM(brand))'))
            ->pluck('c', 'k')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * Vehicles per brand + model text, for the Models list (one query). Keyed "brand|model", lower-cased and trimmed.
     *
     * @return array<string, int>
     */
    public function modelVehicleCounts(): array
    {
        return Vehicle::withTrashed()
            ->selectRaw('LOWER(TRIM(brand)) as b, LOWER(TRIM(model)) as m, COUNT(*) as c')
            ->groupBy(DB::raw('LOWER(TRIM(brand))'), DB::raw('LOWER(TRIM(model))'))
            ->get()
            ->mapWithKeys(fn ($row) => [$row->b.'|'.$row->m => (int) $row->c])
            ->all();
    }

    public static function brandKey(string $brand): string
    {
        return mb_strtolower(trim($brand));
    }

    public static function modelKey(string $brand, string $model): string
    {
        return mb_strtolower(trim($brand)).'|'.mb_strtolower(trim($model));
    }

    /**
     * Find the brand and model for typed names, creating whichever is missing (an imported or older vehicle whose
     * spelling is not in the catalogue yet). Quiet on purpose: no audit row, the vehicle save is the audited act.
     *
     * @return array{0: VehicleBrand|null, 1: VehicleModel|null}
     */
    public function ensure(?string $brandName, ?string $modelName): array
    {
        $brandName = trim((string) $brandName);
        $modelName = trim((string) $modelName);

        if ($brandName === '') {
            return [null, null];
        }

        $brand = $this->findBrand($brandName) ?? VehicleBrand::create(['name' => $brandName, 'is_active' => true]);
        $model = null;

        if ($modelName !== '') {
            $model = $this->findModel($brand->id, $modelName)
                ?? VehicleModel::create(['vehicle_brand_id' => $brand->id, 'name' => $modelName, 'is_active' => true]);
        }

        return [$brand, $model];
    }

    protected function authorize(): void
    {
        abort_unless(self::canManage(auth()->user()), 403, 'You do not have permission to manage the vehicle catalogue.');
    }

    protected function assertBrandNameFree(string $name, ?int $ignoreBrandId): void
    {
        if ($name === '' || $this->findBrand($name, $ignoreBrandId)) {
            throw ValidationException::withMessages(['name' => $name === '' ? 'The brand name is required.' : 'This brand already exists.']);
        }
    }

    protected function assertModelNameFree(int $brandId, string $name, ?int $ignoreModelId): void
    {
        if ($name === '' || $this->findModel($brandId, $name, $ignoreModelId)) {
            throw ValidationException::withMessages(['name' => $name === '' ? 'The model name is required.' : 'This brand already has that model.']);
        }
    }

    /** Soft-deleted vehicles are included on purpose: they keep the catalogue's spelling too. @return Builder<Vehicle> */
    protected function vehiclesWithBrand(string $brandName): Builder
    {
        return Vehicle::withTrashed()->whereRaw('LOWER(TRIM(brand)) = ?', [self::brandKey($brandName)]);
    }

    /** @return Builder<Vehicle> */
    protected function vehiclesWithModel(string $brandName, string $modelName): Builder
    {
        return $this->vehiclesWithBrand($brandName)->whereRaw('LOWER(TRIM(model)) = ?', [mb_strtolower(trim($modelName))]);
    }

    protected function findBrand(string $name, ?int $ignoreId = null): ?VehicleBrand
    {
        return VehicleBrand::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
            ->when($ignoreId, fn ($query, $id) => $query->where('id', '!=', $id))
            ->first();
    }

    protected function findModel(int $brandId, string $name, ?int $ignoreId = null): ?VehicleModel
    {
        return VehicleModel::query()
            ->where('vehicle_brand_id', $brandId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
            ->when($ignoreId, fn ($query, $id) => $query->where('id', '!=', $id))
            ->first();
    }
}
