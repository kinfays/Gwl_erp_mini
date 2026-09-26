# Prompt for Claude Code — Assets module refinements (addendum)

This is a follow-up to the earlier "Redesign the ICT Assets module" prompt for this same repo (`erp_project`, Laravel 13 / Livewire 4.2). Paste it into a Claude Code session at the repo root. It covers two specific, narrower changes. Read `CLAUDE.md` first if you haven't already in this session — same conventions apply (service layer, migration conventions, audit logging, region-scoping).

## 1. Add an image upload option when adding/editing a Model

`ict_asset_models` already has an `image_path` nullable string column (see the migration `2026_05_14_000100_create_assets_module_tables.php` and `App\Models\IctAssetModel`), but there is currently **no management screen for it at all** — `IctAssetModel` rows are only ever read (as the "Model" dropdown source in `Inventory.php`); nothing creates or edits them through the UI. Build that screen now, with an image field:

- Create a Livewire component + view for CRUD on `ict_asset_models` (name, category, manufacturer, is_active, notes, **and an image upload field**). Wire it to the `assets.settings.models` route from the earlier redesign prompt (if that route doesn't exist yet in this session's version of the code, add it under the Assets `Settings` sidebar group).
- Follow the exact shape of `App\Livewire\Staff\LocationsManager` for this component — it's the closest existing analog in this codebase for a small settings CRUD screen: plain create/edit/delete methods, `EnforcesModuleAccess`, a permission-gated `canManage...()` guard, and `AuditLog::record(...)` on every create/update/delete.
- Image handling specifics:
  - Validate as an image (`jpg,jpeg,png,webp`), reasonable max size (e.g. 2MB).
  - Store via Livewire's temporary upload flow to the `public` disk, e.g. `storage/app/public/ict-asset-models/`, and save the resulting relative path into `image_path`. Make sure `php artisan storage:link` is assumed (note it in your summary if it isn't already set up).
  - When an image is replaced or the model is deleted, delete the old file from disk so orphaned images don't accumulate.
  - Show a thumbnail in the models list/table, and (nice-to-have, skip if it complicates things) a small thumbnail next to the model name in the "Model" dropdown on the Add Asset/Phone/Network forms.
  - Add a permission slug for managing models if one doesn't already exist (`assets.manage_models` or similar, seeded the same way the other `assets.*` permissions are).

## 2. Add Asset form — required fields + automatic region

This is about the **Assets** form specifically (computers/laptops/printers/etc. — `App\Livewire\Assets\Inventory` today, or whatever it's been renamed to if you already did the earlier redesign). Two changes:

### a) Make these fields mandatory

Currently in `Inventory::rules()`: `asset_name` and `asset_type` are required, but `serial_number`, `ict_asset_model_id`, `assigned_to_employee_id`, and `district_id` are all `nullable`. Change validation so **Asset Name, Serial Number, Type, Model, Assigned To, and Location are all required**:

- `form.serial_number`: `nullable` → `required` (keep the existing uniqueness rule).
- `form.ict_asset_model_id`: `nullable` → `required`.
- `form.assigned_to_employee_id`: `nullable` → `required`.
- `form.district_id`: `nullable` → `required`. **Note on naming**: in this codebase "Location" = district — see `App\Livewire\Staff\LocationsManager`, which manages the `districts` table under the label "Locations" throughout its UI. Don't add a new `location` concept; just tighten the existing `district_id` field and label it "Location" in the form to match the rest of the app.
- Do **not** add a DB-level `NOT NULL` constraint on `serial_number` as part of this — check first whether any existing rows have a null `serial_number` (`IctAsset::whereNull('serial_number')->count()`); if there are any, a `NOT NULL` migration will fail. Application-level validation on new/edited records is sufficient; handle legacy nulls as a display fallback ("No serial") like the dashboard already does, not by force-filling them.
- Flag, but don't silently fix: the Dashboard's "Unassigned" KPI (`whereNull('assigned_to_employee_id')`) will only ever reflect legacy data once Assigned To is mandatory going forward. Mention this in your summary so it can be reconsidered (e.g. renamed to "Legacy unassigned" or dropped) rather than leaving a KPI that will trend to zero for the wrong reason.

### b) Region becomes fully automatic, not user-selected

Today, `Inventory.php` only auto-locks `region_id` to the actor's own region when `actorIsRegionScopedIct()` is true (ICT Team members who aren't admin/super_admin); everyone else can pick a region manually (or leave it null). Change this so **region is always derived from whoever is entering the data, for every actor, with no manual region field**:

- Remove `region_id` as a user-editable input on the Assets form entirely (display it read-only, like the mock-up's fixed "Accra West" text under the Region label).
- In `mount()` and `resetForm()`, set `region_id` from the actor's own region for **all** actors (reuse the existing `actorRegionId()` helper from `ScopesAssetsByActor` unconditionally, not just when `regionLocked` is true).
- In `save()`, always overwrite `region_id` server-side from `actorRegionId()` regardless of what's in the submitted form data (defense against tampering), the same way it's already forced for region-scoped actors today — just make it unconditional.
- Filter the **Location** (district) dropdown to districts belonging to that auto-derived region, so a user can't pick a district in a different region than their own.
- Handle the edge case where the actor has no employee record / no region on file: block save with a clear, specific validation error (e.g. "Your account has no region assigned — contact an administrator before adding assets.") rather than saving a null region silently, since region is now meant to always be populated.
- This does **not** change how `assigned_to_employee_id`'s own department/district/region backfill works (the existing logic in `save()` that copies the assigned employee's department/district/region onto the asset when those fields are empty) — that logic can stay, but region on this form is now actor-driven and locked before that backfill runs, so double check the precedence: the actor's own region should win for `region_id` specifically; the assigned employee's department/district can still backfill independently.

### Scope note

Only the **Assets** form is in scope for this change right now. If the Phones and Network forms from the earlier redesign prompt exist in this session, do **not** silently apply the same required-fields/auto-region treatment to them — flag in your summary that they're likely candidates for the same treatment and ask before touching them, since that hasn't been confirmed yet.

## Wrap-up

Run `php artisan test --filter=Assets` (or the full suite) after these changes, and add/update feature tests for: the new Models CRUD + image upload/removal, and the tightened Assets-form validation (missing serial/model/assignee/location all rejected; region always forced to the actor's own region and never accepted from form input). Summarize what you built, plus the two flagged items above (unassigned-KPI drift, Phones/Network forms not yet touched) for review.
