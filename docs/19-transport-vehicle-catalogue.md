# Transport: vehicle brands and models (catalogue)

The vehicle form picks a **brand** and then one of that brand's **models** from a small catalogue (`vehicle_brands`, `vehicle_models`). The catalogue is shared by every region and is **not** region-scoped. The vehicle itself keeps `vehicles.brand` and `vehicles.model` as **plain text** copied from the catalogue (reports, exports, search and the import all keep reading them).

## Screens

Transport sidebar > **Settings** > Vehicle Brands / Vehicle Models (shown only to holders of `transport.manage_vehicle_catalog`; `super_admin` and `transport_manager` by default).

- **Brands** (`Livewire\Transport\Settings\VehicleBrandsManager`): list with search, number of models, number of vehicles, status. Add, rename, activate / deactivate.
- **Models** (`Livewire\Transport\Settings\VehicleModelsManager`): list filtered by brand (combobox) plus search, with the number of vehicles that use each. Add (brand required, active brands only), rename, move to another brand, activate / deactivate.

Routes: `transport.settings.brands`, `transport.settings.models` (middleware `module:transport` + `permission:transport.manage_vehicle_catalog`; each component re-checks module access and the permission again on mount and on every action). The screens show vehicle **counts only**, never vehicle data.

The "Add new brand / Add new model" panels on the Add Vehicle form need the same permission: the buttons and panels are shown only to holders, and `Livewire\Transport\Vehicles::authorizeCatalog()` refuses everyone else (on top of `transport.create_vehicles` / `edit_vehicles`). Vehicle users without it still pick from the existing lists.

## The rule: the catalogue changes only through `VehicleCatalogService`

`App\Services\Transport\VehicleCatalogService` is the only place a brand or model is created, renamed, moved or (de)activated: the form panels, the vehicle import (`ensure()`), and these screens all call it. It owns:

- **The duplicate rule**: case-insensitive; a brand name is unique, a model name is unique within its brand. A rename may keep its own name (or change only its case). The rule closures (`brandNameRules()`, `modelNameRules()`) are used by the screens for field errors, and the write methods re-check inside the transaction.
- **The permission**: every write method calls `TransportScope::can($user, 'transport.manage_vehicle_catalog')` and answers 403 otherwise. (`ensure()` is the quiet import path and checks nothing: the vehicle save is the audited act.)
- **The audit row** (`AuditLog::record`, module `transport`): `create_vehicle_brand|model`, `update_vehicle_brand|model`, `activate_` / `deactivate_vehicle_brand|model`, with old and new values; a rename or move also carries `vehicles_updated` (and the old / new brand name for a model).

### Rename and move keep vehicles consistent

Because `vehicles.brand` / `vehicles.model` are text, `updateBrand()` and `updateModel()` rewrite the matching vehicles **in the same DB transaction** as the catalogue row (so a failure rolls both back):

- **Soft-deleted vehicles are included** (`Vehicle::withTrashed()`).
- Matching is on the **old name, case-insensitively and trimmed**. A model also matches on its **old brand**, so "Hilux" under another brand is untouched.
- A brand rename changes `brand` only. A model rename changes `model`; a **move** changes `brand` and `model`.
- The Brand / Model edit screen shows how many vehicles will change (`vehicleCountForBrand()` / `vehicleCountForModel()`, soft-deleted included) in the confirm step, and the audit row stores the same number.

### No delete; deactivate only

Vehicles refer to these names, so nothing is deleted. Deactivating never changes a vehicle. The vehicle form lists active brands/models only, **plus** the one the vehicle being edited already has, so an old vehicle stays editable. A model cannot be added or moved to an inactive brand.

## Permission

`transport.manage_vehicle_catalog`, seeded by `2026_11_09_000001_add_manage_vehicle_catalog_permission.php` (and listed in `PermissionSeeder` / `TransportRolePermissionSeeder`). Granted to `super_admin` and `transport_manager`; others through UAC.

## Tests

`tests/Feature/Transport/VehicleCatalogSettingsTest.php`: permission gating (routes, mounted components, service, sidebar, vehicle-form panels), duplicate rejection, rename / move rewriting `vehicles.brand` / `model` including soft-deleted vehicles, rollback on failure, deactivation keeping vehicles intact, and the audit rows.
