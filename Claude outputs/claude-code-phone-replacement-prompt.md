# Prompt for Claude Code — Phone replacement/issuance (CCA phone swap log)

Paste into a Claude Code session at the `erp_project` repo root. Read `CLAUDE.md` first.

## Context

The ICT team keeps a paper ledger for when staff — most often Customer Care Assistants (CCAs), who go through phones heavily — bring in a faulty/old phone and are issued a replacement. Columns on the paper log: date, staff name, the old device handed in with a short fault description, the new device issued (model), whether the new device is refurbished stock or new, and the new device's serial number. This should become a proper flow in the existing Phones screen rather than a separate paper-style register, reusing the tables that already exist.

**Assumption flagged for review**: this prompt assumes the above column reading is right and that a light extension of the existing Phones form is the right scope (vs. a standalone "Phone Issuance Register" screen). If either is wrong, stop and ask rather than guessing further — the handwriting on the source ledger was genuinely hard to read, so this is a best-effort interpretation, not a confirmed spec.

## Current state (verified)

- `App\Livewire\Assets\PhonesList` (`device_category = IctAsset::DEVICE_CATEGORY_PHONE`) is the existing Phones CRUD screen, with `asset_type` in `['POS', 'SIM', 'Ph']`, `imei`, `user_phone_number`, `device_phone_number` already on `ict_assets`.
- `App\Services\Assets\AssetRecordService::save()` already auto-shifts `assigned_to_employee_id` into `previous_assigned_to_employee_id` on reassignment, and audits every create/update with network-secret redaction (`redact()`). Reuse this service rather than writing new save logic from scratch.
- `IctAssetIssueReport` already exists (`title`, `issue_type`, `reason`, `status`, `linked_asset_id`, region/district, `reported_by_user_id`) and already powers the Issue Reporting screen — reuse it rather than inventing a new table for "what was wrong with the old phone."
- `IctAsset::STATUS_*` is only Active / In Repair / Retired / Lost today — no "Damaged" status exists yet (that's tracked as a separate, not-yet-built gap in `claude/ict-assets-analytics-roadmap.md` §2, Tier B). Don't add it as part of this prompt; use the existing statuses.
- There is currently no link between two `ict_assets` rows representing "this new device replaced that old one" — `previous_assigned_to_employee_id` only tracks *who* held a device before, not *which device* an employee had before. This prompt adds the smallest possible column for that, not a full history table (a fuller movement/transfer history is `claude/ict-assets-analytics-roadmap.md` §3 Tier C — out of scope here).

## What to build

1. **Migration** (guarded, per `CLAUDE.md` conventions): add to `ict_assets`:
   - `is_refurbished` — nullable boolean, default `false`. Conceptually only meaningful for phones, but following this table's existing pattern (it already mixes category-specific nullable columns like `imei`/`ssid` in one table), don't create a separate table for one boolean.
   - `replaces_ict_asset_id` — nullable, `foreignId` constrained to `ict_assets`, `nullOnDelete`. Set on a *new* phone row to point at the *old* phone row it replaced. Add the inverse `replacedBy()` (`hasOne`, via `replaces_ict_asset_id` on the other row) and `replaces()` (`belongsTo`) relations on `IctAsset`.

2. **"Replace Device" action on `PhonesList`** (alongside the existing Edit action, gated by the same `assets.edit` check already used there):
   - Opens a focused flow for the selected (old) phone: a short required **"Old device condition / fault"** text field, a **Refurbished?** yes/no toggle for the *new* device, and a choice of either (a) an existing unassigned phone in stock to reassign, or (b) "+ Add new phone", which opens the normal Phones create form pre-filled with `assigned_to_employee_id` = the same employee.
   - On submit: via `AssetRecordService` (or a small addition to it), set the new phone's `replaces_ict_asset_id` to the old phone's id and `is_refurbished` per the toggle; create an `IctAssetIssueReport` row linked to the **old** phone (`linked_asset_id`), with the typed fault text as `reason` and `issue_type` chosen from whatever vocabulary `IssueReports.php` already uses (read it first — do not invent a new one); leave the old phone's `status` and `assigned_to_employee_id` editable by ICT via the normal edit form afterward (don't guess whether it should go to "In Repair" or stay assigned — that's a judgment call for ICT, not something to automate).
   - Audit both sides of the action distinctly (e.g. `replace_phone_device` with `metadata` carrying both asset ids), following the existing `AuditLog::record(...)` pattern in `AssetRecordService`.

3. **Form field**: add "Refurbished?" to the regular Add/Edit Phone form too (not only the Replace flow), since a phone can be issued as refurbished stock without necessarily replacing a specific broken one (e.g. new hire). Optional, default unset/false.

4. **No role restriction**: this flow applies to any phone swap; Customer Care Assistants are simply the heaviest users of it per the paper log, not a gate in the code. The existing route-group role check (`role:super_admin,ict_team`) already covers who can use the Phones screen at all.

5. **Tests** under `tests/Feature/Assets/`: replacing a phone sets `replaces_ict_asset_id` on the new row and creates an `IctAssetIssueReport` on the old row with the typed reason; `is_refurbished` saves and displays correctly on both the Replace flow and the regular form; audit row carries both asset ids; the old phone's status/assignment are untouched by the replace action itself (left for a separate edit). Run `php artisan test --filter=Assets` when done.

If, on closer reading, the ledger actually records something meaningfully different from this swap-and-issue-report model (for example if "Refurbished Yes/No" actually answers a different question, or the log is really about something other than phone replacement), stop and summarize what you now believe it shows instead of forcing it into this shape.
