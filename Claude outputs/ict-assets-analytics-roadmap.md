ic# ICT Assets — Analytics / ITAM Roadmap (Gap Analysis)

Status: **first-pass gap analysis plus a resolved decision round — Phase 1 prompt handed off, Phase 2 prompt below ready to paste** · Prepared: 2026-10-02, revised 2026-10-02 (decisions round) · Source: `assets doc.docx` (a 17-dimension analytics/ITAM vision supplied by the user), checked against `app/Models/IctAsset.php`, `IctAssetMaintenance.php`, `IctAssetIssueReport.php`, `IctAssetModel.php`, `database/migrations/2026_05_14_000100_create_assets_module_tables.php`, `2026_08_22_000001_add_category_fields_to_ict_assets_table.php`, `app/Services/Assets/AssetDashboardService.php`, `app/Livewire/Assets/{AssetsList,PhonesList,NetworkList,Dashboard}.php`, and `routes/web.php`'s `assets.` group, via the linked device bridge into `C:\laragon\www\erp_project`.

> **2026-10-02, decisions round** — the four open questions from the original draft are resolved (§5). In short: the doc's example replacement-policy years (Laptop 4y, etc.) are **illustrative, not real GWCL policy** — don't hardcode them; **Damaged** becomes its own status paired with a reason (e.g. "Damaged — faulty power button"), **In Repair** specifically means "sent for repair" (so the flow is Active → Damaged (with reason) → In Repair (once dispatched) → Active or Retired), and **Disposed is not a separate status** — Retired already covers it; **Vendor/procurement tracking (Tier E) will not be built**; **Movement/transfer history (Tier C) gets its own table, `ict_asset_transfers`**, as originally recommended. "Available" as a status distinct from "unassigned" was asked about but not confirmed either way — treated as **not pursued for now**, so "unassigned" keeps its current plain meaning (no assignee) rather than gaining a dedicated status. §2's table and §3's tiers are updated accordingly; §6 adds the Phase 2 kickoff prompt (statuses + the transfers table) that these decisions unblock.

This follows the same verify-against-the-real-code approach as `claude/credit-union-module-design.md` and `claude/letters-module-review-and-batch-design.md`.

---

## 1. What the source document asks for

`assets doc.docx` proposes 17 analytical dimensions for the ICT Assets module: (1) an overview KPI row by device type, (2) distribution by department and by location hierarchy, (3) status breakdown with drill-down, (4) condition tracking, (5) age analysis, (6) a replacement forecast driven by age + condition + a per-type replacement policy, (7) assignment analysis (who holds what, unassigned counts), (8) a per-employee asset rollup, (9) movement/transfer tracking with full history, (10) repair/maintenance KPIs including repeat-repair counts and repair-vs-replacement cost, (11) cost analysis (purchase value, book value, repair spend), (12) loss/damage tracking with estimated value and recovery status, (13) warranty status buckets, (14) vendor/procurement tracking, (15) a full asset lifecycle with preserved history, (16) a physical-verification/compliance audit with a reconciliation rate, and (17) a mocked-up executive dashboard tying it together, with the explicit point that clicking a number should drill into the real underlying assets rather than stay a static chart.

That last point — drill-down from a number to a filtered list — is exactly what the companion prompt in this session (dashboard click-through filtering) builds for the existing six KPI cards and the district table, so dimension 17's core UX request is already in motion independent of this doc.

## 2. What's already there vs. what each dimension actually needs

| # | Dimension | State | What exists today | What's missing |
|---|---|---|---|---|
| 1 | Overview KPI cards by type | ✅ shipped | `AssetDashboardService::CARD_TYPES` already gives Computers/Laptops/Printers/Phones/Servers/Network cards with sub-badges (PC/AIO, PRT/PTC, POS/SIM/Ph, RT/SW/AP/MiFi/P2P/4GRT) | Tablets, Cameras, and generic "Other ICT equipment" aren't in `IctAsset::ASSET_TYPES` at all — a real gap only if the org actually has these device types to track |
| 2 | Distribution by department / location | ⚠️ partial | District breakdown already on the dashboard (`AssetDashboardService::buildDistrictBreakdown`); `department_id`/`region_id`/`district_id` all exist as columns and are filterable in `AssetsList` | No **Department** breakdown view exists yet (same shape of query as the district one, just grouped differently); no "Office" sub-level below District — there's no such entity in the schema today |
| 3 | Status breakdown + drill-down | ⚠️ partial, scope now decided | `IctAsset::STATUS_*` = Active, In Repair, Retired, Lost (4 values); drill-down is exactly what the click-through prompt in this session builds | **Decided (§5): add `Damaged`** (paired with a reason, e.g. "Damaged — faulty power button") as a status an asset sits in *before* ICT dispatches it for repair; **`In Repair` keeps its existing meaning**, now explicitly "sent for repair" (so the flow is Active → Damaged → In Repair → Active/Retired); **`Disposed` will not be added** — `Retired` already covers it; **`Available`** (in-store, unassigned) was not confirmed — not pursued for now |
| 4 | Condition | ❌ missing | — | No `condition` column at all (New/Excellent/Good/Fair/Poor/Damaged). Cheapest possible addition: one nullable string column. Shipped as part of Phase 1 (see §4) |
| 5 | Age analysis | ✅ buildable now | `purchased_at` already exists on `ict_assets` | Zero schema change needed — this is purely a new `AssetDashboardService` method bucketing `now()->diffInYears(purchased_at)`, plus a drill-down list. Covered by Phase 1 |
| 6 | Replacement forecast | ⚠️ needs #4 + a real policy | `purchased_at` exists; Condition ships in Phase 1 | **Decided (§5): the doc's example replacement-years-per-type figures are illustrative, not real GWCL policy.** Build the per-`asset_type` "replacement after N years" as an admin-editable config (no hardcoded defaults presented as real policy) and leave it unpopulated until GWCL supplies actual figures — don't ship a forecast that looks authoritative on invented numbers |
| 7 | Assignment analysis | ✅ mostly buildable now | `assigned_to_employee_id` exists; "unassigned" is already a meaningful `whereNull` query | Since "Available" wasn't confirmed as a separate status (§5), "unassigned" keeps meaning exactly what it means today (no assignee) — no change needed here beyond what Phase 1 already covers |
| 8 | Per-employee asset rollup | ✅ buildable now | `assigned_to_employee_id` exists on every category | No schema change — just a grouped query across all three category tables. Covered by Phase 1; gets richer once §9's transfer history exists (a timeline per employee, not just a current snapshot) |
| 9 | Movement / transfer history | ❌→ **decided, scoped in §6** | Only one-hop memory: `previous_assigned_to_employee_id` plus `IctAssetMaintenance` (repair-only) | **Decided (§5): its own table, `ict_asset_transfers`**, modeled on Letters' `routing_histories`. Designed and scoped as Phase 2 in §6 |
| 10 | Repair/maintenance KPIs | ⚠️ partial | `IctAssetMaintenance` has `maintenance_type`, `status`, `completion_date`, `technician`; repeat-repair count is already free (`$asset->maintenanceLogs()->count()`) | No `cost` column on `ict_asset_maintenances` — needed for both "average repair cost" and "repair cost vs replacement cost". Not yet scheduled into a phase |
| 11 | Cost analysis | ❌ missing | — | No `purchase_price` on `ict_assets`, no `cost` on `ict_asset_maintenances`. "Book value" (depreciated) is a computed value once purchase price + a real replacement-years policy (#6) exist — and #6's policy figures are still pending real numbers from GWCL, so this stays blocked until then |
| 12 | Loss/damage tracking | ⚠️ partial | `status` already has `Lost`, and now `Damaged` (§5/§6); `IctAssetIssueReport` already has date/region/district/reporter | No "Stolen" distinction, no `estimated_value`, no `recovery_status` — cheapest fit is extending `IctAssetIssueReport` rather than a new table. Not yet scheduled into a phase |
| 13 | Warranty status | ✅ buildable now | `warranty_expires_at` already exists on `ict_assets` | Zero schema change — purely a dashboard bucket (active / expiring 30d / expiring 90d / expired). Covered by Phase 1 |
| 14 | Vendor / procurement | **Decided (§5): not building** | — | — |
| 15 | Full lifecycle with history | ⚠️ states exist, history doesn't | `status` values now cover the real lifecycle per §5 (Active/Damaged/In Repair/Retired/Lost) | The "preserved history" half is the *same* table as #9 — `ict_asset_transfers`, scoped in §6, not a second build |
| 16 | Compliance / physical-verification audit | ❌ missing | — | Needs an `ict_asset_audits` (one verification run) + `ict_asset_audit_lines` (per-asset expected/verified/mismatch-reason) pair — conceptually close to the Credit Union module's reconciliation idea and the Letters module's register-export idea. Not yet scheduled into a phase |
| 17 | Executive dashboard mockup | — | Already the direction the dashboard is heading (cards + district breakdown + donut) | Mostly a matter of adding the above tiles as they ship, plus the drill-down behavior the click-through prompt in this session already covers |

## 3. Recommended phasing

- **Tier A — shipped as Phase 1 (§4)**: Age analysis (#5), Warranty status (#13), Status drill-down, Assignment/unassigned + per-employee rollup (#7, #8), plus the Condition column pulled forward from Tier B since it's one column and unlocks the Replacement forecast later.
- **Tier B — scoped as Phase 2 (§6)**: the `Damaged` status + reason (§5 decision), and every existing `STATUS_*` reference in the module updated to handle it correctly.
- **Tier C — scoped as Phase 2 (§6)**: `ict_asset_transfers` — the movement/transfer history table (§5 decision), serving both Movement analysis (#9) and Lifecycle history (#15).
- **Not yet scheduled**: Repair cost tracking (#10), Cost analysis (#11, blocked on real replacement-policy figures), Loss/damage extensions (#12), Compliance/audit (#16). Pick these up as their own phase once Phase 2 ships — each needs its own short scoping pass the way Phase 1 and 2 got, not a guess baked into this doc.
- **Decided against**: Vendor/procurement tracking (#14, Tier E).

## 4. Phase 1 kickoff prompt (Tier A) — already handed off

Scoped to what's buildable with at most one new nullable column (`condition`), no new tables, and no dependency on anything that wasn't yet decided. This was delivered earlier in the same session; kept here for the record and because Phase 2 (§6) assumes it has shipped.

```
Phase 1 of the ICT Assets analytics work, per claude/ict-assets-analytics-roadmap.md
(§2, §3 Tier A) and CLAUDE.md. This assumes the dashboard click-through filtering prompt
from this same session has already shipped (or ships alongside this) — Status drill-down
and the per-employee rollup both want to reuse that pattern (a plain, bookmarkable link
from a number to a filtered list), not a one-off click handler.

Read app/Services/Assets/AssetDashboardService.php, app/Models/IctAsset.php,
app/Livewire/Assets/{AssetsList,PhonesList,NetworkList,Dashboard}.php, and
resources/views/livewire/assets/dashboard.blade.php first — ground every change in what
these actually look like today, not the roadmap doc's prose.

1. Age analysis: add a method to AssetDashboardService bucketing all scoped assets by
   now()->diffInYears(purchased_at) into 0-1 / 1-2 / 2-3 / 3-4 / 4-5 / 5+ years (assets
   with a null purchased_at go in their own "Unknown" bucket, not silently dropped or
   lumped into 5+). Add an "Asset Age" card to the dashboard (x-ui.bar-list or similar,
   matching the existing visual language) with each bucket linking to the relevant list
   filtered by an age range — this needs a new age-range query param on AssetsList/
   PhonesList/NetworkList (e.g. age_min/age_max in years), following the same #[Url]
   query-binding approach the click-through prompt introduces.
2. Warranty status: add a method bucketing by warranty_expires_at into Active / Expiring
   within 30 days / Expiring within 90 days / Expired / Unknown (null). Add a "Warranty
   Status" card with the same drill-down-by-range treatment. Do not build a scheduled
   notification in this phase — just the dashboard view.
3. Assignment analysis: add an "Unassigned Assets" tile (reuse the existing whereNull
   assigned_to_employee_id query, scoped the same way everything else is) linking to the
   relevant list filtered to unassigned; add a small "Employees with the most assets"
   list (top 10, count across all three categories by assigned_to_employee_id) as its own
   card or table, each row linking to that employee's full asset list.
4. Per-employee asset rollup: a new screen (or a drawer/expandable section on an existing
   one — your call) showing one employee's assets across Assets + Phones + Network in one
   place, since today those are three separate Livewire components with no combined view.
   Keep this read-only; don't change the underlying list components' category split.
5. Condition: migration adding a nullable condition column to ict_assets (Schema::
   hasColumn guarded, per CLAUDE.md's migration conventions), values New / Excellent /
   Good / Fair / Poor (add as public consts on IctAsset, mirroring how ASSET_TYPES/
   STATUS_* are already declared — note: Damaged is being added as a STATUS in a later
   phase, not a condition value, so don't duplicate it here). Add it to the Assets/
   Phones/Network forms as an optional field and to AssetRecordService's save() path. Do
   NOT build the replacement-forecast engine itself in this phase.
6. Tests under tests/Feature/Assets/ for every new dashboard card's numbers and every new
   drill-down link's filtering; run php artisan test --filter=Assets when done.

Do not build Movement/transfer history, the Replacement forecast engine, Cost analysis,
Loss/damage extensions, new statuses, Vendor tracking, or the Compliance/audit tables in
this phase — those are later phases.
```

## 5. Decisions (resolved 2026-10-02)

1. **Replacement-policy years** — the doc's example figures (Laptop 4y, Desktop 5y, Phone 3y, POS 4y, Printer 5y, Router 4y) are **illustrative, not real GWCL policy**. Any Replacement forecast or Cost/Book-value feature must treat per-type replacement years as an admin-editable setting with no invented defaults — it stays effectively unimplemented until GWCL supplies real figures.
2. **Status vocabulary** — `Damaged` becomes its own status, always paired with a reason (free text, e.g. "Damaged — faulty power button"); `In Repair` keeps its current meaning, now made explicit as "sent for repair" (i.e. the step *after* Damaged, once ICT dispatches the asset); `Disposed` will **not** be added as a separate status — `Retired` already covers it. `Available` (distinct from "no assignee") was asked about but not confirmed — treated as not pursued; "unassigned" keeps its current plain meaning.
3. **Vendor/procurement tracking (Tier E)** — will not be built.
4. **Movement/transfer history (Tier C)** — gets its own table, `ict_asset_transfers`, as originally recommended (mirrors Letters' `routing_histories`). Designed in §6.

## 6. Phase 2 kickoff prompt (Damaged status + ict_asset_transfers) — ready to paste

Assumes Phase 1 (§4) has shipped. Read what it actually built first — the condition column, the dashboard age/warranty/assignment cards, the `#[Url]`-bound query filters — rather than assuming the prose above is exactly what landed.

```
Phase 2 of the ICT Assets analytics work — the Damaged status and asset movement/transfer
history — per claude/ict-assets-analytics-roadmap.md §5 (decisions) and §2 (dimensions 3,
9, 15), and CLAUDE.md. Assumes Phase 1 has shipped (the condition column, the dashboard's
age/warranty/assignment cards, #[Url]-bound list filters). Run git log --oneline -20 and
read app/Services/Assets/AssetDashboardService.php, app/Models/IctAsset.php,
app/Services/Assets/AssetRecordService.php, and app/Livewire/Assets/{AssetsList,PhonesList,
NetworkList}.php first to confirm exactly what Phase 1 actually added before changing
anything — follow the real code over this prompt's assumptions where they disagree.

If the "Replace Device" phone-swap prompt from this same session has already been run
(check for a replaces_ict_asset_id column on ict_assets and a Replace Device action in
PhonesList.php), read that too — this phase's transfer logging should fold that flow in
rather than leave it as a separate, un-logged mechanism.

1. Damaged status: add IctAsset::STATUS_DAMAGED = 'Damaged' alongside the existing
   STATUS_ACTIVE/STATUS_IN_REPAIR/STATUS_RETIRED/STATUS_LOST consts. Add a nullable
   status_reason text column to ict_assets (migration, Schema::hasColumn guarded) —
   generic rather than Damaged-specific, since a reason is useful context for Lost/
   Retired too, but required-when-Damaged in the Assets/Phones/Network forms'
   validation (AssetsList/PhonesList/NetworkList's rules()). Do NOT add Available or
   Disposed statuses — both were explicitly decided against/not pursued (§5).
2. Grep every reference to IctAsset::STATUS_ across app/ and resources/ (controllers,
   Livewire components, AssetDashboardService, the status-pill component's domain="asset"
   tone mapping, any status dropdowns in the three category forms, seeders/factories,
   tests) and make sure Damaged is handled everywhere a status is listed, filtered, or
   styled — this is exactly the kind of gap Phase 1's design note warned about. Give
   Damaged its own status-pill tone (distinct from the existing warning tone already used
   for In Repair) so the two are visually distinguishable on sight.
3. New migration (hasTable guarded, explicit FK delete behaviour, never edit an existing
   migration) creating ict_asset_transfers:
     id
     ict_asset_id          FK ict_assets, cascadeOnDelete
     transfer_type         string, indexed — one of: assignment_change, status_change,
                            district_change, replacement (add as public consts on a new
                            IctAssetTransfer model, same pattern as IctAsset's own consts)
     from_employee_id      FK employees, nullable, nullOnDelete
     to_employee_id        FK employees, nullable, nullOnDelete
     from_status           string, nullable
     to_status             string, nullable
     from_district_id      FK districts, nullable, nullOnDelete
     to_district_id        FK districts, nullable, nullOnDelete
     related_asset_id      FK ict_assets, nullable, nullOnDelete  -- the other asset in a
                            'replacement' transfer (the old phone when this row is the new
                            phone's transfer, or vice versa)
     reason                text, nullable
     performed_by_user_id  FK users, nullable, nullOnDelete
     occurred_at           timestamp, default now
     timestamps
     index [ict_asset_id, occurred_at]
   Flat model app/Models/IctAssetTransfer.php; add a transfers(): HasMany relation on
   IctAsset ordered by occurred_at desc.
4. app/Services/Assets/AssetTransferService.php with a single log(IctAsset $asset, string
   $type, array $attrs, ?int $actorUserId) method. Call it from
   AssetRecordService::save()'s existing update branch: compare old vs new
   assigned_to_employee_id (log 'assignment_change' with from/to employee), status (log
   'status_change' with from/to status — this is what finally gives the Active -> Damaged
   -> In Repair -> Active/Retired flow a real audit trail), and district_id (log
   'district_change') — one transfer row per changed dimension, not one row trying to
   capture everything that changed in a single update. Do not log a transfer on create
   (there's nothing to transfer from yet).
5. If the Replace Device phone flow exists (see the check above), have it call
   AssetTransferService::log() with type='replacement' on both the old and new asset
   (related_asset_id pointing at each other), in addition to whatever it already does
   with replaces_ict_asset_id and the IctAssetIssueReport it creates — don't replace that
   existing behaviour, add the transfer-log call alongside it.
6. Display: add a read-only "History" section (a simple reverse-chronological list is
   enough — no need for a dedicated page) to each category's edit/detail view, showing
   that asset's transfers with a plain-language line per row (e.g. "Reassigned from
   Kwame Mensah to Ama Boateng — 2 Oct 2026" / "Status changed: Active -> Damaged
   (faulty power button) — 2 Oct 2026"). This is what makes dimensions 9 and 15
   (movement + lifecycle) actually visible, not just logged.
7. Audit: AuditLog::record(...) is already called by AssetRecordService's own create/
   update audit rows — do not duplicate that for every transfer; the transfers table IS
   the detailed record for status/assignment/district changes, while audit_logs keeps
   its existing module-level before/after snapshot. Keep the password redaction
   AssetRecordService::redact() already applies.
8. Tests under tests/Feature/Assets/: Damaged requires a reason and is rejected without
   one; every status dropdown/filter in the module includes Damaged; status-pill renders
   a distinct tone for Damaged vs In Repair; changing assigned_to_employee_id, status, or
   district_id on an existing asset creates exactly one matching ict_asset_transfers row
   per changed field with correct from/to values; creating a new asset logs no transfer;
   the Replace Device flow (if present) logs a 'replacement' transfer on both assets with
   related_asset_id set correctly both ways; the History section renders a real asset's
   transfer list in reverse-chronological order. Run php artisan test --filter=Assets.

Do not build Available or Disposed statuses, Cost analysis, Loss/damage extensions,
Repair cost tracking, or the Compliance/audit tables in this phase — none of those were
decided or scoped yet; each needs its own short pass first.
```