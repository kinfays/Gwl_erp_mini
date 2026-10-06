# Commercial Module — Billing & Meter-Reading Analytics: Design and Phase 1 Kickoff

> **2026-10-05 — first draft.** Scope: a new `commercial` module that ingests the two summary reports GWCL's billing system produces (**`rptReadingSummDate`** — meter-reading performance by reader, and **`rptBillingSumm_ExP`** — billing summary by route within district) and turns them into dashboards, trends, rankings and exports. Data arrives by **Excel upload, weekly or monthly** — there is no live link to the billing system.
>
> Verified against the real code (`DeductionImportService`, `Deductions` Livewire component, `Permission`, `ErpNavigation`, `routes/web.php`, `config/gwl.php`, `Employee`/`District`/`Region`, the Assets `Summary` + `AssetSummaryService`) and against the two sample files the user supplied. Where the design depends on something not yet confirmed with the Commercial team, it is listed in §10 rather than assumed.
>
> Follows the same approach as `credit-union-module-design.md` (batch header + line rows, preview → validate → post) and `ict-assets-analytics-roadmap.md` (one service feeding the screen and the exports; every number drills into real rows).

---

## 1. What the source reports actually are

Both files are **pre-aggregated report exports**, not flat tables. That drives the whole design: the existing import screens (`DataImportService`, `DeductionImportService`) expect one header row followed by data rows on the first sheet, which is not what these look like.

### 1.1 `rptReadingSummDate` — "Customer Meter Reading Report – CCA Summary – By Month"

- **74 sheets.** `Document map` (a list of 72 `staffId - NAME` entries + the System Administrator), then **one sheet per meter reader** (72 readers, including the `00000 - System Administrator` account), then a final **grand-total sheet**.
- Every reader sheet has the same layout: the filter block (`REPORT PERIOD`, `REGION`, `DISTRICT` list), the reader label in `D9` (`15071 - BISMARK ABRAH OWUSU`), then one row per month with **Verified Strength, Read #/%, Skipped #/%, Visited #/%, Unvisited #/%**, and a per-reader totals row.
- **Verified Strength is region-wide, not per reader** (59,392 for June on every sheet), so every percentage the source prints per reader is *of the whole region's customers* and is meaningless for judging a reader (e.g. 0.89% "read rate"). The module must recompute rates from counts. Likewise the grand-total sheet's "Unvisited" (16.9 million) adds the region's strength once per reader and must not be imported as a metric.
- The reader's **staff ID** is in the label — it should match `employees.staff_id` (to be confirmed on real data, §10).
- The filter block says *Region: ACCRA WEST; District: SOWUTUOM, ODORKOR, KANESHIE, DARKUMAN/GBAWE, AMASAMAN* — but **there is no per-district or per-route split in the numbers**. A reader's row is their total across the region.

### 1.2 `rptBillingSumm_ExP` — "Billing Summary Report By Routes – Routes By District"

- **One sheet**, with one block per district (header row `District | VOLUMES ('000 Litres) | AMOUNTS (GH¢) | Collection | Number Of | NUMBER BILLED | NUMBER UNBILLED`, then a `Route` column-header row, then one row per route, then a `<DISTRICT> TOTALS:` row), then `REPORT TOTALS :`, then a **domestic (category 611) band table** (`<=5` and `>5` thousand litres: customers, volume, amount).
- Filter block: `BILL PERIOD: June-2026 – August-2026`, `REGION: ACCRA WEST`, **`BILLING STATUS: New Service Customers Only`**. So this sample is one *period* (three months rolled together) and one *customer segment*. The module must treat period and segment as dimensions of the upload, not constants.
- Columns per route: Volume (Actual, Average, Total) · Amounts (Opening Balance, Billing For Period, Total Receivable, Revenue Adjustment, Payment For Month, Prev Month Payment, Offset Payments, Total Payments, Closing Balance) · Collection Ratio · Number Of Customers · Number Billed (Average Metered, Average Unmetered, Actual Reading, Total Billed) · Number Unbilled (Suspense Metered/Unmetered, Disconnected Metered/Unmetered, Other Status, Total Unbilled).
- 108 route rows across 5 districts (Darkuman/Gbawe 28, Sowutuom 26, Amasaman 25, Odorkor 23, Kaneshie 10 — minus header rows).

### 1.3 What the sample files told us (checked, not assumed)

| Check | Result |
|---|---|
| Reader rows summed per month == grand-total sheet (read / skipped / visited) | **Pass** — 182,881 read, 44,364 skipped, 227,245 visited |
| `visited = read + skipped` on reader rows | Pass on the figures spot-checked |
| Billing: `Total Receivable = Opening + Billing + Adjustment` | **Pass, 0 of 108 routes off** |
| Billing: `Total Payments = Payment For Month + Prev Month + Offset` | Pass, 0 off |
| Billing: `Closing = Receivable − Payments` | Pass, 0 off |
| Billing: `Total Billed = Avg Metered + Avg Unmetered + Actual Reading`; `Total Unbilled = sum of the five reasons` | Pass, 0 off |
| Route rows summed == `REPORT TOTALS` (volumes, every amount column, billed, unbilled) | **Pass** |
| `Collection Ratio` column | `#VALUE!` on zero-billing routes; the overall 101.72% equals `Total Payments ÷ Billing For Period` — recompute, don't import |
| `Number Of Customers` column vs billed count | **Does not reconcile** — 326 vs 593 billed + 48 unbilled; 88 of 108 routes differ. Its meaning needs confirming (§10 Q2) before it is charted. |

The passing reconciliations are what make an **import-time control-total check** both possible and valuable: a file that doesn't tie out to its own totals has been truncated, filtered or edited, and should not be loaded.

### 1.4 First reading of the numbers (illustrative, from the two samples)

These are the kinds of insight the module will surface, computed from the sample files — they are not part of the build.

- **Reading:** reads rose 43,523 → 47,083 (Jun→Aug) then fell to 45,912 in Sep; the skip rate is ~20% of visits; individual reader skip rates range from ~4% to ~41%; 3–4 readers record zero visits in each month.
- **Billing (new-service customers, Accra West, Jun–Aug):** GH¢38.6k billed, GH¢39.3k paid (cash ratio 101.7%) — but **63% of that cash (GH¢24.8k) was previous-month payments**; only GH¢14.5k (37.5% of billing) was paid in the month billed. ~38% of billed volume is estimated (average) rather than read. 108 domestic customers using >5k litres (19% of domestic customers) produce 62% of domestic revenue (GH¢21.4k of GH¢34.5k).

---

## 2. Proposed module: `commercial`

Module slug `commercial`, title **Commercial**, short **Commercial**. Behind a feature flag exactly like Credit Union: `config('gwl.commercial_module_enabled')` (env `GWL_COMMERCIAL_MODULE_ENABLED`, default `false`), filtered in `ErpNavigation::moduleDefinitions()`.

### 2.1 Permissions & roles

Add to `app/Models/Permission.php`:

```php
public const MODULE_COMMERCIAL = 'commercial';
// ...append to MODULES
```

| Slug | Purpose |
|---|---|
| `commercial.view_dashboard` | Overview KPI page |
| `commercial.view_billing` | Billing analytics (districts, routes, collections, estimation, balances, bands) |
| `commercial.view_reading` | Reading analytics at region/month level |
| `commercial.view_reader_performance` | **Per-reader** league table, quality and exception screens — individual staff performance, so a separate, tighter permission |
| `commercial.upload_reports` | Upload/preview/confirm report batches |
| `commercial.resolve_matches` | Resolve unmatched readers/districts, manage location aliases |
| `commercial.void_batches` | Void or supersede an imported batch |
| `commercial.export_reports` | Excel/PDF export |
| `commercial.manage_settings` | Targets and thresholds (**proposed: super_admin only**, as with the Assets replacement policy — to confirm) |

Roles: `commercial_officer` (uploads, resolves matches, views everything, exports) and `commercial_manager` (views everything incl. reader performance, exports; no upload). Existing management roles (`chief_manager`, `regional_chief_manager`, `district_manager`) get module access with `view_dashboard`/`view_billing`/`view_reading`, region-scoped (§2.4); whether they also get `view_reader_performance` is a decision for §10.

Seeding follows the Credit Union precedent exactly: **inside a migration** with `DB::table(...)->updateOrInsert(...)` for roles, permissions, `module_access` (every existing role listed explicitly, `false` for those that should not see it, including `employee`) and `insertOrIgnore` for `role_permissions`.

### 2.2 Data model

All money `decimal(15,2)` (balances can be negative); volumes `decimal(15,2)` in **thousand litres**; counts `unsignedInteger`. Flat models under `app/Models/` (`Commercial…`), explicit `$table` where pluralisation is not obvious. `Schema::hasTable()` guards and explicit FK delete behaviour in every migration.

**`commercial_import_batches`** — one row per uploaded file (the unit of audit, void and supersede).

| Column | Notes |
|---|---|
| `report_type` | `reading_summary` \| `billing_summary` (indexed) |
| `region_id` | FK `regions`, `nullOnDelete`; resolved from the file's `REGION:` line via alias |
| `region_label_raw` | the text as printed (`ACCRA WEST`) |
| `period_from`, `period_to` | dates; reading: first and last month shown; billing: from `BILL PERIOD` (month starts/ends) |
| `granularity` | `monthly` (single month) \| `multi_month` (billing periods spanning months — see §3.3) |
| `billing_status_raw`, `customer_segment` | billing only; `New Service Customers Only` → segment `new_service`; null/`all` otherwise |
| `source_filename`, `file_path` | stored on the private disk, e.g. `commercial/imports/…` |
| `file_hash` | sha256, **unique** — the same file twice is refused |
| `status` | `imported` \| `superseded` \| `voided` (indexed) |
| `row_count`, `matched_count`, `warning_count` | for the batch list |
| `control_totals` | JSON — totals read from the file's own total rows/sheet |
| `reconciliation_passed` | boolean |
| `supersedes_batch_id` | self-FK, `nullOnDelete` |
| `imported_by` / `imported_at`, `voided_by` / `voided_at` / `void_reason`, `notes` | audit |

**Reading side**

- `commercial_reading_strengths` — `batch_id` (cascade), `month` (date, first of month), `verified_strength`; unique `(batch_id, month)`. Region-wide, so one row per month, **not** per reader.
- `commercial_reading_stats` — `batch_id` (cascade), `month`, `reader_staff_id` (string), `reader_name_raw`, `employee_id` (FK `employees`, `nullOnDelete`), `district_id` (the reader's *home* district from the directory at import time, nullable), `read_count`, `skipped_count`, `visited_count`, `match_status` (`matched` \| `unmatched` \| `system_account`); unique `(batch_id, month, reader_staff_id)`; index `(month, employee_id)`. The source's `%` and `Unvisited` columns are **not** stored.

**Billing side**

- `commercial_billing_routes` — `batch_id` (cascade), `district_id` (FK `districts`, `nullOnDelete`), `district_label_raw`, `route_code` (`AMASAMAN 4601`), then every column in §1.2: `volume_actual`, `volume_average`, `volume_total`, `opening_balance`, `billing_for_period`, `total_receivable`, `revenue_adjustment`, `payment_for_month`, `prev_month_payment`, `offset_payments`, `total_payments`, `closing_balance`, `customers_count` (see §10 Q2), `billed_average_metered`, `billed_average_unmetered`, `billed_actual_reading`, `billed_total`, `unbilled_suspense_metered`, `unbilled_suspense_unmetered`, `unbilled_disconn_metered`, `unbilled_disconn_unmetered`, `unbilled_other`, `unbilled_total`; unique `(batch_id, district_label_raw, route_code)`; index `(district_id, batch_id)`. `Collection Ratio` is **not** stored (recomputed).
- `commercial_billing_bands` — `batch_id` (cascade), `category_code` (`611`), `band` (`<=5`, `>5`), `customers`, `volume`, `amount`.

**Lookup**

- `commercial_location_aliases` — `kind` (`region` \| `district`), `alias_normalized` (upper-cased, whitespace-collapsed report text), nullable `region_id` / `district_id`; unique `(kind, alias_normalized)`. The importer tries an exact normalised match against `regions.region_name` / `districts.district_name` first, then the alias table; anything else is an **unmatched location** shown on the batch screen. Resolving it once writes an alias so every later upload matches automatically.

### 2.3 "Effective" data rule (how weekly and monthly uploads coexist)

Rows are **immutable snapshots tied to a batch** — nothing is overwritten. Analytics read the **effective** rows:

- **Reading:** for each `(region, month, reader)`, the row from the **most recent non-voided batch whose months include that month**. A weekly upload therefore refreshes the current month's month-to-date figures while earlier months keep their latest known value.
- **Billing:** for each `(region, customer_segment, period_from, period_to)`, the most recent non-voided batch wins; older ones become `superseded` when the officer confirms the replacement.
- Because older snapshots are kept, the module can also show **how a month filled up across weekly uploads** (month-to-date pace) — see §5, Phase 4.

Implement as query scopes on the stat/route models (`scopeEffective()`), used by every service method so a screen and its export can never disagree.

### 2.4 Scoping

- Regional management users see only their own region's batches/rows (`employee->region_id`); head-office users and `super_admin` see all. A new `ScopesCommercialByActor` trait modelled on `Assets\Concerns\ScopesAssetsByActor` (region id from `$user->employee ?? $user->employeeByStaffId`) — **a separate trait, as that file's own comments and CLAUDE.md's note on the duplicated `EnforcesModuleAccess` traits warn against assuming shared code.**
- Layered authorization per CLAUDE.md: route middleware (`module:commercial`, `permission:…`) **and** Livewire `enforceLivewireModule(Permission::MODULE_COMMERCIAL)` + a per-action guard, as `Deductions::guardManageDeductions()` does.

---

## 3. Import pipeline

Same shape as Credit Union's deduction import — **upload → preview → validate → confirm → batch + lines** — in `app/Services/Commercial/`, but with parsers for the report layout instead of a header-row template.

### 3.1 Services

- `Services/Commercial/ReadingSummaryImportService` and `BillingSummaryImportService`, each exposing `preview(UploadedFile)`, `createBatch(array $attributes, array $parsed, ?string $path, ?int $actorId)`, and a `rematch…` method, returning the same preview-array shape as `DeductionImportService::preview()` (`errors`, `warnings`, counts, `blocked`) so the Livewire screens can be copied.
- `Services/Commercial/ReportFileReader` — shared helpers: read all sheets, find cells by **label** rather than fixed coordinates (so a column shifting by one doesn't silently mis-load), normalise cell text/amounts (reuse the `normalizeCellText` / `normalizeAmount` logic — copy, don't refactor the Credit Union service), and detect the report type from the title text (`Customer Meter Reading Report` / `Billing Summary Report`).
- `Services/Commercial/LocationMatcher` — region/district resolution and alias lookup (§2.2).
- **Multi-sheet reading:** `RawRowsImport` is `ToCollection` and only sees the first sheet, so the reading importer needs its own import class (`App\Imports\Commercial\…`) that returns every sheet. Check which Maatwebsite API (`WithMultipleSheets` vs `Excel::toArray`) fits the version pinned in `composer.lock`. Sheets arrive as raw cell grids; parse them by label.
- Upload validation: `mimes:xlsx` only (these are system exports; CSV would lose the layout), size cap from `config('gwl.commercial_import_max_mb')`.

### 3.2 Parsing rules

**Reading**

1. Skip `Document map` and the final grand-total sheet for stats, but **read the grand-total sheet's Read/Skipped/Visited totals as control totals**.
2. For each reader sheet: parse `staffId - NAME` from the label cell; read the month rows (`Mon 01-Jun-2026` → `2026-06-01`), `Strength`, `Read #`, `Skipped #`, `Visited #`. Ignore `%`, `Unvisited`, and the per-reader totals row (recompute).
3. `00000 - System Administrator` → `match_status = system_account`; imported (it has real counts) but excluded from every ranking and from reader counts.
4. Staff-ID match via `employees.staff_id`; fill `employee_id` and the reader's home `district_id` if found.
5. Filter block → `region_label_raw` (`REGION:` line); `period_from`/`period_to` from the month rows actually present (the printed `REPORT PERIOD` end date, `01-October`, is exclusive of any October row).

**Billing**

1. Locate the filter block: `BILL PERIOD`, `REGION`, `BILLING STATUS`. Parse `June-2026 - August-2026` → `2026-06-01`…`2026-08-31`; set `granularity = multi_month` when it spans more than one month.
2. Walk the sheet: a row whose label column is a district name and whose next cells hold `VOLUMES ('000 Litres)` starts a district block; the next `Route` row is the column header (map columns **by header text**); rows until `… TOTALS:` are routes; capture the district-totals row for reconciliation only.
3. `REPORT TOTALS :` → control totals. The block after it titled `DOMESTIC (611) CATEGORY BREAKDOWN` → `commercial_billing_bands`.
4. Non-numeric cells (`#VALUE!`) → `null`, not an error; they only occur in the ratio column, which is not stored.

### 3.3 Validation — what blocks and what only warns

Unlike the deduction import (where every unmatched row is lost money and counts toward `max_import_failure_percent`), a report with an unmatched reader or unknown district is still *useful* and the fix is a one-click resolution afterwards. So:

- **Blocking (batch cannot be imported):** wrong/unrecognised report layout; no readable data rows; **control-total mismatch** (reader rows ≠ grand-total sheet; routes ≠ district totals ≠ `REPORT TOTALS`; any per-route identity in §1.3 fails by more than 0.02); duplicate `file_hash`; region cannot be resolved (it is the access-scoping key — resolve via alias before import); `visited ≠ read + skipped` beyond a tolerance.
- **Warning (imported, flagged on the batch screen):** reader staff ID not in the directory; district not matched; a district block with no routes; a billing period spanning multiple months (cannot feed month-level trends — see below); a month already covered by an earlier batch (will supersede, shown explicitly with the count of rows affected).
- Keep `config('gwl.max_import_failure_percent')` out of this module; reconciliation is pass/fail, not a percentage.

**Multi-month billing periods.** The sample covers June–August as one block, so it can feed *period* analyses (district comparison, bands, estimation) but **not a monthly trend**. The module must say so on the Billing screens ("trend needs single-month uploads") rather than plotting one point. The recommended practice is to export billing **one month at a time** (§10 Q3).

### 3.4 Screens (Livewire, thin Blade wrappers per CLAUDE.md)

- **Uploads** (`Commercial\Batches`) — table of batches (type, region, period, segment, rows, status, uploaded by/when) with filters; **Upload** modal with report-type auto-detect, preview (counts, reconciliation panel, warnings), and **Import**. Modelled on `Deductions`.
- **Batch detail** (`Commercial\BatchShow`) — summary, reconciliation results, warnings; **unmatched readers/districts** list with *Resolve* actions (link to employee / map to district → saves alias → re-match); **Void** (reason required; permission `void_batches`). Modelled on `DeductionBatchShow`.
- Audit trail via `App\Support\Audit::log(action:, module:, targetType:, targetId:, metadata:)`: `commercial.batch_imported` (with matched/unmatched/warning counts and control totals), `commercial.batch_superseded`, `commercial.batch_voided`, `commercial.alias_saved`, and exports.

---

## 4. Analytics catalogue (what the screens compute)

Everything is computed by services from **effective** rows (§2.3) and takes the already region-scoped query + filters, as `AssetSummaryService` does, so scoping lives in one place. Every figure drills to the underlying rows (`#[Url]` filters + a row table), matching the Assets "click a number to see the real records" principle.

### 4.1 Reading (reader-level screens gated by `view_reader_performance`)

| # | Analysis | Definition |
|---|---|---|
| R1 | Monthly volume trend | Σ read, skipped, visited per month |
| R2 | Coverage | `visited ÷ verified_strength` per month (region) |
| R3 | Skip rate | `skipped ÷ visited` per month and per reader |
| R4 | Read rate / conversion | `read ÷ visited` |
| R5 | Strength growth | Δ `verified_strength` month on month |
| R6 | Reader league table | visits per reader, ranked; share of region total |
| R7 | Reader quality | skip rate vs visits (scatter); top/bottom decile |
| R8 | Reader consistency | coefficient of variation of monthly visits; months below own average |
| R9 | Month-on-month movement | per-reader Δ visited, Δ skip rate (improving / declining) |
| R10 | Inactive / under-utilised | `visited = 0` in a month, or below `commercial_workload_low_pct` of the median (system account excluded) |
| R11 | Workload balance | distribution of visits across readers; max÷median; readers beyond the high/low band |
| R12 | Skip-rate outliers | z-score above `commercial_outlier_zscore`, **only for readers with ≥ `commercial_min_visits_for_outlier` visits** (avoids flagging a reader with 197 visits) |
| R13 | Reader scorecard | composite of volume, skip rate, consistency (weights in settings) |
| R14 | Month-to-date pace | for a month covered by several weekly batches, visited/read at each snapshot vs the same point of prior months |

### 4.2 Billing (all region/period level; no staff data)

| # | Analysis | Definition |
|---|---|---|
| B1 | District and route ranking | volume, billing, customers billed; Pareto share of top-N routes |
| B2 | Balance roll-forward | opening → +billing → ±adjustment → −payments → closing, per district/route |
| B3 | Cash collection ratio | `total_payments ÷ billing_for_period` (matches the source's overall 101.7%) |
| B4 | Current-period collection | `payment_for_month ÷ billing_for_period` and the **share of cash that is prior-month** (`prev_month_payment ÷ total_payments`) — shows how much cash is arrears recovery vs. fresh billing |
| B5 | Estimation share | `volume_average ÷ volume_total`; and `(avg metered + avg unmetered) ÷ billed_total` by count |
| B6 | Unbilled analysis | `unbilled_total ÷ (billed_total + unbilled_total)`, split by the five reasons |
| B7 | Credit-balance exposure | Σ and count of routes with negative closing balance; largest credits (sign convention to confirm, §10 Q1) |
| B8 | Revenue per customer / per kL | `billing ÷ billed_total`; `billing ÷ volume_total` ('000 litres = m³) |
| B9 | Domestic consumption bands | customers, volume, amount, share, GH¢/m³ per band (tiered-tariff effect visible: ~8.4 vs ~11.5 GH¢/m³ in the sample) |
| B10 | Metered vs unmetered mix | by count and volume |
| B11 | Route exceptions | zero activity, heavy credit, high unbilled share, high estimation share, high Δ vs prior period |
| B12 | Period-over-period comparison | any two single-period batches of the same segment (needs single-month uploads for a real trend) |

### 4.3 Combined (Phase 4)

| # | Analysis | Note |
|---|---|---|
| C1 | District scorecard | billing + estimation + collection + unbilled per district; **plus reading metrics through the reader's home district** from the staff directory. This is an **approximation** — it assumes a reader works in the district they are posted to; label it as such on screen and in exports. A real link needs a per-route or per-district reading export (§10 Q3). |
| C2 | Estimation vs skip rate | does a higher regional skip rate precede a higher estimated-billing share? (regional only, until C1's caveat is resolved) |
| C3 | Executive KPI summary | the handful of headline numbers + exceptions, one page, Excel/PDF |

### 4.4 Settings (defaults in `config/gwl.php`, editable later)

`commercial_min_visits_for_outlier` (default 200), `commercial_outlier_zscore` (2.0), `commercial_workload_low_pct` (50), `commercial_workload_high_pct` (150), and **target placeholders** `commercial_target_skip_rate_pct`, `commercial_target_coverage_pct`, `commercial_target_collection_pct`. **The target values are placeholders until the Commercial team confirms real ones** (§10 Q5); ship them as config now, move to a `commercial_targets` table + settings screen in Phase 5.

---

## 5. Suggested phased delivery

1. **Foundation + import (highest value, everything else depends on it)** — migrations (batches, reading strengths/stats, billing routes/bands, aliases), permission/role/module-access seeding, feature flag, navigation, **both importers with preview and control-total reconciliation**, batch list + detail, location alias resolution, void. Ends with data safely in the tables and an audit trail; no charts yet beyond a simple batch summary.
2. **Reading analytics** — R1–R5 region trend and coverage on the Commercial home; R6–R13 reader screens behind `view_reader_performance`.
3. **Billing analytics** — B1–B12 as pill tabs on a Billing page, in the shape of the Assets `Summary` page (URL-bound tab/district/period filters, one service, cards that drill down).
4. **Combined + exports + pace** — C1–C3, R14 month-to-date pace, Excel/PDF exports following the Letters register pattern (one service feeding preview, Excel and PDF; `WithCustomValueBinder` string binder because reader names and route labels are user text and a leading `=` would otherwise become a formula; Dompdf with DejaVu Sans, `isRemoteEnabled=false`, A4 landscape; audit each export).
5. **Settings and housekeeping** — targets/thresholds screen (super_admin), a scheduled reminder in `routes/console.php` when no batch of a given type has arrived within N days (the transport expiry checks are the precedent), optional notification to officers.

---

## 6. Open implementation notes

- **Sample files contain staff names and customer-route data.** Use synthetic fixtures in tests (generate small `.xlsx` files that mimic the layout); do **not** commit the real exports.
- Store uploaded originals on the private disk so a batch can be re-parsed after a parser fix; never serve them publicly.
- Large reading uploads: the sample is ~1.6 MB / 74 sheets — fine for an interactive upload, but keep parsing in the service (not the Livewire component) so it can move to a queued job if files grow.
- Do **not** reuse `DataImportService` or `RawRowsImport` for these reports; add alongside, per the "follow existing patterns, don't refactor neighbours" approach used for Letters.

---

## 7. Phase 1 kickoff prompt — foundation + import (ready to paste into Claude Code)

```
Implement Phase 1 of the Commercial module for this ERP, per commercial-module-design.md
(sections 1, 2, 3 and the Phase 1 line of section 5). Read first: CLAUDE.md, the Credit Union
module as the closest precedent — app/Services/CreditUnion/DeductionImportService.php,
app/Livewire/CreditUnion/Deductions.php and DeductionBatchShow.php,
database/migrations/2026_09_11_000001_create_credit_union_deduction_tables.php and
2026_09_10_000002_seed_credit_union_module_access.php — plus app/Models/Permission.php,
app/Support/ErpNavigation.php (moduleDefinitions(), sidebarFor(), creditUnionSidebar()),
the credit-union route group in routes/web.php (around line 408), config/gwl.php,
app/Imports/RawRowsImport.php, app/Livewire/Assets/Concerns/ScopesAssetsByActor.php,
and the Employee / District / Region models. Follow CLAUDE.md conventions throughout.

Scope — this phase is ONLY: migrations, permissions/roles/module access, navigation + feature
flag, the two importers with preview and reconciliation, the batch list/detail screens,
location-alias resolution, and void. No analytics dashboards or exports yet.

1. Migrations (new files; never edit existing ones; Schema::hasTable() guards; explicit FK
   delete behaviour):
   - create_commercial_module_tables: commercial_import_batches, commercial_reading_strengths,
     commercial_reading_stats, commercial_billing_routes, commercial_billing_bands,
     commercial_location_aliases — exactly the columns, uniques and indexes in design §2.2.
     Money decimal(15,2) (negative allowed); volumes decimal(15,2) in '000 litres.
   - seed_commercial_module_access: modelled on the Credit Union seed migration — roles
     commercial_officer and commercial_manager; the nine permissions in design §2.1; a
     module_access row for EVERY existing role (true for super_admin, commercial_officer,
     commercial_manager, chief_manager, regional_chief_manager, district_manager; false for all
     others including employee); role_permissions via insertOrIgnore. Use
     Permission::MODULE_COMMERCIAL, not string literals.
2. Add Permission::MODULE_COMMERCIAL ('commercial') to the constants and MODULES.
3. config/gwl.php: commercial_module_enabled (env GWL_COMMERCIAL_MODULE_ENABLED, default false),
   commercial_import_max_mb, and the four threshold/target keys from design §4.4 (add them to
   .env.example too). In ErpNavigation::moduleDefinitions() add the Commercial module
   (slug commercial, title "Commercial", icon_name from the icons already in use, route
   commercial.home) and extend the existing array_filter so it is hidden unless the flag is on,
   exactly like credit_union. Add commercialSidebar($user) and the match arm in sidebarFor():
   Overview (commercial.home), Uploads (commercial.batches), each item with a 'can' closure
   using permission slugs or super_admin.
4. Models (flat under app/Models/, no factories needed): CommercialImportBatch,
   CommercialReadingStrength, CommercialReadingStat, CommercialBillingRoute,
   CommercialBillingBand, CommercialLocationAlias. Add status/type constants on the batch model
   (STATUS_IMPORTED/SUPERSEDED/VOIDED, TYPE_READING_SUMMARY/BILLING_SUMMARY) in the style of
   CreditUnionDeductionBatch, and a scopeEffective() on the stat and route models implementing
   design §2.3 (latest non-voided batch wins per region/month/reader, and per
   region/segment/period for billing).
5. Services in app/Services/Commercial/: ReportFileReader (read every sheet as raw cell grids,
   find cells by LABEL not fixed coordinates, normalise text/amounts — copy the
   normalizeCellText/normalizeAmount behaviour of DeductionImportService, do not refactor it,
   detect report type from the title text), LocationMatcher (normalised exact match against
   regions.region_name / districts.district_name, then commercial_location_aliases),
   ReadingSummaryImportService and BillingSummaryImportService with preview(), createBatch()
   and rematch methods returning the same preview-array shape as DeductionImportService
   (errors, warnings, counts, blocked). Parsing rules, validation and what blocks versus warns:
   design §3.2 and §3.3. RawRowsImport only reads the first sheet, so add a new import class
   under app/Imports/Commercial/ for the multi-sheet reading file (check composer.lock for the
   Maatwebsite version and use WithMultipleSheets or Excel::toArray accordingly). Reconciliation
   is pass/fail: reader rows must equal the grand-total sheet per measure; billing routes must
   equal district totals and REPORT TOTALS and satisfy the per-route identities within 0.02;
   duplicate file_hash is refused. Do NOT store the source's percentage, Unvisited or
   Collection Ratio columns. The system account 00000 gets match_status system_account.
   Do not reuse DataImportService or RawRowsImport.
6. Livewire (app/Livewire/Commercial/, thin Blade wrappers in resources/views/commercial/ and
   real templates in resources/views/livewire/commercial/): Home (placeholder overview showing
   the latest batch per report type), Batches (list + upload modal + preview + import, modelled
   on Deductions), BatchShow (summary, reconciliation panel, warnings, unmatched readers/districts
   with Resolve actions that save an alias and re-match, Void with a required reason, modelled
   on DeductionBatchShow). Use Livewire\Concerns\EnforcesModuleAccess
   (enforceLivewireModule(Permission::MODULE_COMMERCIAL)) AND a per-action permission guard.
   Create a ScopesCommercialByActor trait (separate from the Assets one) that scopes batches by
   the actor's employee region for non-head-office users. Routes: a commercial. group in
   routes/web.php with module:commercial and permission: middleware, in the shape of the
   existing module blocks.
7. Audit: App\Support\Audit::log for commercial.batch_imported (counts + control totals),
   commercial.batch_superseded, commercial.batch_voided, commercial.alias_saved.
8. Tests under tests/Feature/Commercial/ (plain PHPUnit, RefreshDatabase, inline fixtures, no
   factories). Generate SMALL synthetic .xlsx fixtures that mimic the two report layouts — do
   not use or commit real exports. Cover: each importer reads a valid fixture into the expected
   rows; a fixture whose reader totals do not match the grand-total sheet is blocked; a billing
   fixture with a broken per-route identity is blocked; the same file twice is refused;
   an unmatched reader or district imports with a warning and Resolve + re-match fixes it and
   saves an alias; an alias is reused on the next upload; the system account is excluded from
   reader counts; scopeEffective() picks the newer batch when two cover the same month and
   ignores a voided one; a regional user cannot see or void another region's batch; users
   without commercial.upload_reports get 403 on upload; the module is hidden from navigation
   when the flag is off and visible when on; the seed migration is idempotent on re-run.
   Run: php artisan test --filter=Commercial. Report back anything in design §10 you needed to
   assume.
```

---

## 8. Later-phase kickoff prompts

Written when Phase 1 lands, against the real schema — same practice as the Assets roadmap. Phase 2 (reading analytics) is next.

---

## 9. Out of scope

Direct integration with the billing system (API/database) — uploads only for now, by design; per-customer or per-meter analysis (the source reports do not contain it); billing for customer segments other than the one uploaded (the model supports it via `customer_segment`, but only `new_service` has been seen); tariff modelling.

---

## 10. Questions to confirm with the Commercial team (before or during Phase 1)

1. **Balance sign convention** — is a *negative* opening/closing balance a customer **credit** (deposit/prepayment) as assumed in B7, or an arrears-type figure? The sample shows many routes at −100/−200/−300, which looks like deposits on new connections.
2. **"Number Of Customers" (collection group)** — what does it count? It totals 326 against 593 billed + 48 unbilled and differs on 88 of 108 routes, so it is not the billed count. Charting it blind would mislead.
3. **Export granularity** — can the billing system export (a) billing **one month at a time** (so monthly trends work), and (b) the reading report **by district or route** (so reader performance can be tied to areas instead of the home-district approximation)? This is the single biggest lever on analysis quality.
4. **Other segments** — will you also upload billing for existing/other customer segments, or only *New Service Customers*?
5. **Targets** — official targets for skip rate, coverage and collection ratio (the config defaults are placeholders).
6. **Who sees individual readers** — should `view_reader_performance` include regional chief managers/district managers (for their own region), or only head-office Commercial?
7. **Reader staff IDs** — confirm they match `employees.staff_id` in the directory (the module reports the match rate on first import); and confirm the report's `ACCRA WEST` maps to an existing region (an alias handles any naming difference).
8. **Home-district proxy (C1)** — acceptable as a labelled approximation until a district-level reading export exists?
9. **Cadence and ownership** — who uploads, and weekly or monthly? (Weekly uploads of the reading report refresh the current month; the design supports both.)
