# Prompt for Claude Code — Phase 3: Replacement forecast

Paste into a Claude Code session at the `erp_project` repo root. Read `CLAUDE.md` first. This follows Phase 1 (condition column, age/warranty/assignment dashboard cards) and Phase 2 (Damaged status, `ict_asset_transfers`) — grounded in `claude/ict-assets-analytics-roadmap.md` §5 (decision 1, corrected) and §7 in the project. Re-verify against the actual current code/git log rather than trusting this prose.

## Context

The replacement policy is real — ICT replaces a device after 4 years — but it must be stored as an admin-editable setting, not hardcoded in code, so it can be changed if the policy changes later. The storage should also leave room for a future per-asset-type override (e.g. phones on a different cycle than servers) even though only one flat figure (4 years, applying to every asset type) is confirmed today — don't force a second migration later just because today's answer is "one number for everything."

## Current state (verify before relying on it)

- `purchased_at` already exists on `ict_assets`.
- `condition` (Phase 1) and the Damaged status + `status_reason` (Phase 2) should both already exist — confirm via `git log` and reading `IctAsset.php` before building on top of them.
- `App\Livewire\Assets\Settings\{ManufacturersManager,ModelsManager,IpRangesManager}` are the existing pattern for an admin settings screen under Assets > Settings — a new "Replacement Policy" settings screen should follow the same shape (Livewire CRUD component, route under `assets.settings.*`, sidebar entry in `App\Support\ErpNavigation::assetsSidebar()`), not invent a new convention.
- Permission seeding follows the `updateOrInsert` pattern in `2026_08_22_000001_add_category_fields_to_ict_assets_table.php` (`assets.manage_ip_ranges`, `assets.view_network_secrets`) — use the same shape for the new permission.
- Phase 1's age-bucketing method on `AssetDashboardService` is the template for this phase's due-date bucketing — read it and follow its structure rather than reinventing it.

## What to build

### 1. Migration: `ict_asset_replacement_policies`

```php
Schema::create('ict_asset_replacement_policies', function (Blueprint $table) {
    $table->id();
    $table->string('asset_type')->nullable()->unique(); // null row = default/fallback policy
    $table->unsignedSmallInteger('years');
    $table->timestamps();
});
```

Seed exactly **one** row in the migration: `asset_type = null, years = 4`. This is the real, confirmed policy — unlike Phase 1's condition values, it's fine to seed this specific business number directly.

### 2. `IctAssetReplacementPolicy` model

- Flat Eloquent model.
- Static helper `yearsFor(string $assetType): int` — looks up an exact `asset_type` override first, falls back to the `asset_type = null` default row, and falls back to a hardcoded `4` only as a last-resort safety net if the table is somehow empty (shouldn't happen given the seed, but a dashboard metric should never hard-fail because a seeder didn't run in some environment).

### 3. Forecast bucketing

- New method, either on a new `App\Services\Assets\AssetReplacementService` or a new method on `AssetDashboardService` (check how `AssetDashboardService` is organized first and pick whichever fits the existing service boundary better).
- Bucket every scoped asset by `due_date = purchased_at->addYears(yearsFor($asset_type))` into: **Overdue** (due date in the past) / **Due within 90 days** / **Due within 1 year** / **Not due yet** / **Unknown** (`purchased_at` is null).

### 4. Dashboard card

- Add a "Replacement Forecast" card to the dashboard, matching the visual language of the Age and Warranty cards from Phase 1 (same component — check what those actually used).
- Each bucket links to the relevant list filtered to that bucket — add a new query param (e.g. `replacement_bucket`) on `AssetsList`/`PhonesList`/`NetworkList` via the same `#[Url]` binding approach Phase 1 introduced. This is a **sibling** filter to the age-range one, not a replacement for it.

### 5. Settings screen

- New Livewire component under Assets > Settings, alongside Models and IP Ranges (register it in `ErpNavigation::assetsSidebar()`).
- Lets an authorized user view/edit the default replacement-years figure, and optionally add a per-asset-type override row.
- Gate with a new permission, e.g. `assets.manage_replacement_policy`, seeded the same way `assets.manage_ip_ranges` was. Default to granting it to `super_admin` only (this is policy-level, not day-to-day asset management) — flag this choice explicitly in your summary in case ICT should have it too; don't just assume and move on silently.

### 6. Audit

- Audit every change to the policy via `AuditLog::record()` (module Assets) — a policy change affecting every asset's forecast should be traceable, the same way `AssetRecordService` already audits asset changes.

### 7. Tests

Under `tests/Feature/Assets/`, cover:

- `yearsFor()` resolves an override before falling back to the default row.
- Changing the default from 4 to another value changes the forecast for assets with no override.
- The dashboard forecast card buckets correctly, including the Unknown bucket for null `purchased_at`.
- The settings screen is unreachable without `assets.manage_replacement_policy`.
- Editing the policy writes an audit log row.

Run `php artisan test --filter=Assets` when done.

## Explicitly out of scope for this prompt

- Cost analysis (purchase price / book value) — still blocked on a `purchase_price` column that doesn't exist yet, unrelated to this phase.
- Loss/damage extensions, repair cost tracking, compliance/audit tables — all unscheduled, per the roadmap doc's §3.

Summarize at the end: what you built, and explicitly call out the super_admin-only permission decision from step 5 so it can be corrected if wrong.
