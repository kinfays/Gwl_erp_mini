# Prompt for Claude Code — Phase 7: UPS tracking (AIO/Desktop/Printer-Copier/Server)

Paste into a Claude Code session at the `erp_project` repo root. Read `CLAUDE.md` first. This follows Phases 1–6 — grounded in `claude/ict-assets-analytics-roadmap.md` §11 in the project. Run `git log --oneline -20` and read the current `app/Models/IctAsset.php`, `app/Livewire/Assets/AssetsList.php`, `resources/views/livewire/assets/assets-list.blade.php`, `app/Services/Assets/AssetRecordService.php`, `app/Services/Assets/AssetSummaryService.php`, and `resources/views/livewire/assets/summary.blade.php` (the "Needs Attention" banner, from Phase 5/6) before changing anything — confirm these actually look the way this prompt assumes.

## Context

ICT wants to track, for **AIO, Desktop (PC), Printer/Photocopier, and Server** assets only (not Laptops, not Phones, not Network devices — those run on their own battery or aren't typically on building UPS backup), whether the device sits on a UPS, and if so, whether that UPS is currently working or spoiled.

## What to build

### 1. Migration (guarded, per `CLAUDE.md` conventions)

Add to `ict_assets`:

- `has_ups` — nullable boolean, default `false`.
- `ups_status` — nullable string. Values `Working` / `Spoiled` (add `IctAsset::UPS_STATUS_WORKING`/`UPS_STATUS_SPOILED` consts and a `UPS_STATUSES` array, mirroring how `STATUSES` is already declared). Required only when `has_ups` is true.
- `ups_notes` — nullable text. Optional free-text context (e.g. "battery dead, replacement ordered 20 Oct"). Not required even when spoiled — keep this low-friction, not another mandatory reason field.

Add `has_ups`/`ups_status`/`ups_notes` to `IctAsset::$fillable`, and `'has_ups' => 'boolean'` to `$casts`.

### 2. Form — `AssetsList.php` / `assets-list.blade.php` only

These three fields apply **only** to `asset_type` in `[PC, AIO, Server, PRT, PTC]` — **not** `Laptop**, and not at all on `PhonesList`/`NetworkList` (different device categories, out of scope). Show the UPS section conditionally based on the currently-selected `asset_type` in the add/edit form (a reactive show/hide, the same way any existing type-conditional field in this form already toggles — check for a precedent before inventing a new mechanism). When `asset_type` is `Laptop` (or anything outside the five asset types above), hide the section and clear `has_ups`/`ups_status`/`ups_notes` to `false`/`null`/`null` on save rather than leaving stale values sitting in the database.

Validation: `ups_status` is `required_if:has_ups,true`; clear it to `null` when `has_ups` is false (add it to the existing nullable-cleanup loop in `AssetRecordService::save()` alongside `ict_asset_model_id`/`assigned_to_employee_id`/etc., so an empty selection never gets stored as an empty string).

No new audit code needed — `AssetRecordService::save()` already snapshots the full before/after row via `AuditLog::record()`, so these fields are covered automatically. No new permission needed — the existing `assets.edit`/equivalent gate on `AssetsList` already covers it.

### 3. List filter + display

- Add a filter on `AssetsList` (e.g. `ups` — `#[Url(as: 'ups')]`, values `all`/`has_ups`/`no_ups`/`spoiled`) following the same `#[Url]` query-binding pattern already established in this module, so a filtered view is bookmarkable.
- Add a small badge/column to `assets-list.blade.php`'s table showing UPS state for rows where it applies (e.g. a `x-ui.status-pill` reading "UPS: Working" / "UPS: Spoiled", blank for rows where `asset_type` doesn't carry a UPS at all) — reuse the existing status-pill component and tone conventions (Spoiled should read as a warning/danger tone, consistent with how Damaged reads elsewhere in this module).

### 4. Needs Attention banner (Summary page, Phase 5/6)

Add **Spoiled UPS** as a third signal in the "Needs Attention" list on the Summary page (`AssetSummaryService`/`Summary.php`/`summary.blade.php`) alongside the existing two (Damaged status, unresolved issue report) — this is exactly the kind of concrete, objective signal Phase 6 said to wait for before adding a third one, and it's here now. Each matching asset links to its edit view, same as the other two signals.

### 5. Summary page — small UPS coverage stat

Add a small stat/card to the Summary page's **Lifecycle** tab (alongside Age/Warranty/Replacement Forecast — it's the same kind of "equipment health" indicator) showing counts: **On UPS** / **No UPS** / **Spoiled UPS**, each linking to the `AssetsList` filtered via the new `ups` query param. Keep this to one small card — not a new tab, not a new page.

### 6. Tests

Under `tests/Feature/Assets/`, cover: `ups_status` is required when `has_ups` is true and rejected without one; saving with `has_ups` false clears `ups_status`/`ups_notes` to null; the UPS section only applies to `PC`/`AIO`/`Server`/`PRT`/`PTC` — selecting `Laptop` (or saving a Phone/Network asset, if those forms are ever touched by mistake) never persists these fields; the `ups` filter on `AssetsList` returns the right rows for each value including `spoiled`; the Needs Attention banner includes exactly the assets with `ups_status = Spoiled` and no others; the Lifecycle tab's new stat counts match seeded data. Run `php artisan test --filter=Assets` when done.

## Explicitly out of scope for this prompt

- Network devices and Phones — no UPS concept for this phase, per the user's explicit scope (AIO/Desktop/Printer-Copier/Server only).
- A dedicated UPS asset/inventory record (treating the UPS itself as its own trackable device with a serial number, warranty, etc.) — this phase is just "does this device have one, and is it working," not a UPS inventory system. Flag as a possible follow-up if ICT turns out to want to track UPS units themselves (brand, capacity, battery replacement date), not just a yes/no per device.
- Reporting a spoiled UPS through the Phase 6 issue-reporting taxonomy as its own category/subtype — the direct field on the asset covers the immediate ask; adding "UPS" as an issue subtype under Computer/Printer categories is a small, separate follow-up if ICT wants to log *when* and *who reported* it went spoiled, rather than just the current state.

Summarize at the end: confirm the UPS section only shows/applies for the five intended asset types, and which tab you added the coverage stat to if you deviated from Lifecycle.
