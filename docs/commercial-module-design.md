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

## 8. Phase 1 as built (reviewed 2026-10-06) and Phase 2 kickoff

### 8.1 What Phase 1 delivered

Reviewed against the repository: the six tables match §2.2 (`2026_10_05_000003_create_commercial_module_tables.php`), the seed migration matches §2.1 (`…_000004_seed_commercial_module_access.php`, every existing role gets an explicit `module_access` row), models are flat under `app/Models/Commercial*.php` with `scopeEffective()` on stats, strengths and routes, services live in `app/Services/Commercial/` (`ReportFileReader`, `LocationMatcher`, `ReadingSummaryImportService`, `BillingSummaryImportService`, `BatchLifecycleService`, `BatchResolutionService`, plus thin dispatch services), Livewire in `app/Livewire/Commercial/` (`Home`, `Batches`, `BatchShow`) with its own `Concerns\ScopesCommercialByActor`, and tests in `tests/Feature/Commercial/` (access/navigation, billing import, reading import, effective data, seed migration).

Things worth knowing that the code does and the design did not spell out:

- The `commercial.` route group is registered **only when `config('gwl.commercial_module_enabled')` is true** (`routes/web.php`), so the flag must be on to see routes, not just navigation. After flipping `GWL_COMMERCIAL_MODULE_ENABLED=true` run `php artisan config:clear` (and `route:clear` if routes are cached).
- Config defaults chosen by the implementation: coverage target **90**, collection target **95**, skip-rate target **10**. These are **placeholders, not agreed targets** (§10 Q5) — do not present them to users as official.
- Region scoping is by the actor's employee `region_id`; `super_admin`, `admin` (Global Admin) and Head Office staff (`location_type = 'HeadOffice'`) see every region. A user with no region sees nothing.
- `Home` is still a "latest data loaded" table; no analytics yet.
- Not yet verified: the test suite has not been run by the author of this section — run `php artisan test --filter=Commercial`, and import the two real sample files on a **local** copy (see 8.2) before relying on the importers.

### 8.2 Before Phase 2: a real-data smoke test (15 minutes, high value)

Import the two real exports through the Uploads screen on a local database and check, in order: (1) both files pass reconciliation; (2) the reading batch's **staff-ID match rate** against the directory — this settles §10 Q7; if many readers are `unmatched`, fix the staff IDs or add the missing employees before building reader screens on top; (3) `ACCRA WEST` resolves to a region (or resolve it once via alias); (4) the five district names resolve. Do not commit the real files.

### 8.3 Phase 2 scope — reading analytics (R1–R13)

Region/month-level trend and coverage (R1–R5) need `commercial.view_reading`; per-reader screens (R6–R13) need `commercial.view_reader_performance`. R14 (month-to-date pace) stays in Phase 4. No exports yet (Phase 4).

**Rules fixed here so the screens and the numbers agree**

- Inputs are always `CommercialReadingStat::query()->effective()->readers()` and `CommercialReadingStrength::query()->effective()`, restricted to the actor's region(s). Add `scopeReadingStatsForActor()` / `scopeStrengthsForActor()` to `ScopesCommercialByActor` (filter through `batch.region_id`), so scoping is in one place.
- **Rates are recomputed from counts**, never taken from the source: skip rate = `skipped ÷ visited`; read rate = `read ÷ visited`; coverage = `visited ÷ verified_strength` for the month (strengths summed over the regions in view). Guard every division by zero (return `null`, show "–").
- **The current calendar month is "in progress"** (weekly uploads fill it gradually). Show it in the trend with an "in progress" marker, but exclude it from month-on-month movement, inactive-reader detection, consistency and outlier statistics, which use **complete months only**. If the range contains no complete month, say so instead of computing.
- **Home district is the reader's directory district at import time** (`stat.district_id`). Any district filter on reading screens filters by reader home district and is labelled "reader's home district" — the source has no real district split.
- System account rows are already excluded by `readers()`; unmatched readers (`match_status = unmatched`) **are** ranked, flagged "Not in staff directory", and link to the batch's resolve screen.
- Readers need ≥ `commercial_min_visits_for_outlier` visits in the range to enter outlier statistics; consistency (coefficient of variation of monthly visits) needs ≥ 3 complete months, else show "not enough months".
- Workload bands use the median visits of **active** readers (visited > 0) and `commercial_workload_low_pct` / `commercial_workload_high_pct`.
- R13 scorecard = weighted percentile rank (volume, inverted skip rate, inverted CV) scaled 0–100; weights in a new config key `commercial_scorecard_weights` (default volume 0.4, skip 0.4, consistency 0.2). They are a first guess — show the weights on screen and label the score "indicative".

### 8.4 Phase 2 kickoff prompt (ready to paste into Claude Code)

```
Implement Phase 2 of the Commercial module (reading analytics, R1–R13) per
docs/commercial-module-design.md sections 4.1 and 8.3. Read first: CLAUDE.md, the whole
design doc, then the Phase 1 code you will build on — app/Models/CommercialReadingStat.php
and CommercialReadingStrength.php (scopeEffective(), scopeReaders()), CommercialImportBatch.php,
app/Livewire/Commercial/Home.php and Concerns/ScopesCommercialByActor.php,
tests/Feature/Commercial/CommercialTestCase.php and EffectiveDataTest.php (reuse their
fixture helpers), the commercial block in routes/web.php (it is inside
if (config('gwl.commercial_module_enabled'))), commercialSidebar() in
app/Support/ErpNavigation.php, config/gwl.php — and the pattern to copy for the UI and the
service: app/Livewire/Assets/Summary.php, resources/views/livewire/assets/summary.blade.php,
app/Services/Assets/AssetSummaryService.php and resources/js/charts.js.

FIRST run `php artisan test --filter=Commercial` and report the result before changing
anything. If anything fails, stop and report; do not fix Phase 1 silently.

Build:
1. app/Services/Commercial/ReadingAnalyticsService.php. Every method takes the
   already-scoped, already-filtered effective queries (stats and strengths) and returns plain
   arrays/collections — the Livewire component owns scoping and filters, exactly as
   AssetSummaryService does. Fetch the needed rows with one query and aggregate in PHP (about
   72 readers x a handful of months); no per-row queries; no DB-specific SQL (the test DB
   is SQLite). Methods, definitions in design section 4.1 and the rules in 8.3:
   monthlyTrend (R1 + R3 + R4 per month, with an in_progress flag), coverage (R2),
   strengthGrowth (R5), readerTable (R6: visits, share of total, read, skipped, skip rate,
   months active), quality (R7), consistency (R8), movement (R9: complete months only),
   inactive (R10), workload (R11), outliers (R12), scorecard (R13). Put thresholds in
   config('gwl.commercial_*') — add commercial_scorecard_weights to config/gwl.php and
   .env.example. Never divide by zero; return null for undefined rates.
2. Extend ScopesCommercialByActor with scopeReadingStatsForActor() and
   scopeStrengthsForActor() (restrict through batch.region_id; same visibility rules as
   scopeBatchesForActor). Do not change Phase 1 behaviour.
3. Livewire (app/Livewire/Commercial/, thin Blade wrappers in resources/views/commercial/,
   templates in resources/views/livewire/commercial/):
   - Home: keep the "Latest data loaded" table, and above it, for users with
     commercial.view_reading / view_dashboard, KPI stat tiles for the latest COMPLETE month
     (visited, read, skip rate, coverage, each with the change against the month before and the
     configured target shown as a muted reference, labelled "configured target") plus the
     monthly trend chart (x-ui.chart; copy the usage from the Assets summary view; load
     charts.js with @assets/@vite as that view does).
   - Reading (route commercial.reading): pill tabs with <x-ui.segmented>, #[Url] state for
     tab, region (only shown to users who see all regions), district (reader's home district),
     from, to — as Assets Summary does. Tabs: "Trend & coverage" (R1–R5; needs view_reading)
     and, shown only with commercial.view_reader_performance, "Readers" (R6 league table,
     R7 quality, R8 consistency, R9 movement), "Exceptions" (R10 inactive, R11 workload,
     R12 outliers) and "Scorecard" (R13, with the weights shown). Tables use x-ui.table; every
     figure that summarises rows links or filters to those rows.
   - ReaderDetail (route commercial.reading.reader, parameter = reader staff id; needs
     view_reader_performance and a region check — a regional user must get 403 for a reader
     whose rows are all in another region): the reader's monthly table and trend, name,
     directory district, and match status.
   - Authorization is layered as in Phase 1: route middleware (permission:...),
     enforceLivewireModule(Permission::MODULE_COMMERCIAL), and a per-tab/action guard so a user
     without view_reader_performance cannot reach reader data by editing the ?tab= URL.
   - Add a "Reading" item to commercialSidebar() visible with view_reading or
     view_reader_performance. Routes go inside the existing flag-guarded commercial group.
4. Show a clear empty state when no reading batch exists, and an "in progress" badge on the
   current calendar month everywhere it appears. Label target values as configured targets,
   not official ones.
5. Tests under tests/Feature/Commercial/ (plain PHPUnit, RefreshDatabase, reuse
   CommercialTestCase fixtures, no real exports): trend and coverage numbers for a
   hand-computed fixture; division by zero returns null, not an error; the in-progress month
   is shown in the trend but excluded from movement/inactive/consistency/outliers; the system
   account never appears; an unmatched reader is ranked and flagged; effective data is used
   (a newer batch changes a month, a voided batch is ignored); a reader below the minimum
   visits is not flagged as an outlier even with a high skip rate; consistency shows "not
   enough months" under 3 complete months; workload bands use the median of active readers;
   a regional user sees only their region and gets 403 on another region's reader; a user
   with view_reading but not view_reader_performance cannot see reader tabs or the detail
   page (also via ?tab= in the URL); the sidebar item follows the permissions; Home tiles
   reflect the latest complete month.
   Run: php artisan test --filter=Commercial.
6. Update docs/commercial-module-design.md section 8 with a short "Phase 2 as built" note
   listing anything you decided that the design did not say, and add a short Commercial
   entry to CLAUDE.md's module list and folder notes if it is not there yet.
```

### 8.5 Phase 2 as built (2026-10-06)

`app/Services/Commercial/ReadingAnalyticsService.php` holds R1-R13; `Livewire\Commercial\Reading` (route `commercial.reading`, tabs Trend & coverage / Readers / Exceptions / Scorecard), `ReaderDetail` (`commercial.reading.reader`, parameter = staff ID) and the reworked `Home` (KPI tiles + trend) use it. `ScopesCommercialByActor` gained `scopeReadingStatsForActor()` / `scopeStrengthsForActor()`; the sidebar has a **Reading** item (visible with `view_reading` or `view_reader_performance`). New config: `commercial_scorecard_weights` (env `GWL_COMMERCIAL_SCORE_WEIGHT_*`). Tests: `ReadingAnalyticsTest` (hand-computed numbers) and `ReadingScreensTest` (screens, scoping, permissions); Phase 1 tests untouched except the sidebar label list.

Decisions the design did not spell out:

- **Rates are percentages** (`33.33`, not `0.3333`), rounded to 2 dp, so they compare directly with the config targets and thresholds. `null` when the denominator is 0, shown as "–".
- **"Complete month"** = any month before the current calendar month at "now". The in-progress month appears in the trend, the league table (R6/R7) and the reader page with an **In progress** badge, and is excluded from movement, inactive, workload, consistency, outliers and the scorecard.
- **R7 quality:** the design asked for a scatter; `x-ui.chart` has no scatter type, so R7 is two tables (lowest / highest skip rates). Only readers with at least `commercial_min_visits_for_outlier` visits **per active month** are ranked (see R12); the "tenth" is `ceil(n / 10)`, at least 1.
- **R8 consistency:** population standard deviation / mean over the reader's complete months; needs 3 complete months in the range *and* 3 months for that reader after their first visit (leading zero-visit months are a new starter's months before they began, so they are dropped; a zero month after they started stays in).
- **R9 movement:** the last two complete months in range; a skip-rate change under 0.5 points is "steady".
- **R10 inactive:** per complete month, "no visits" and "below `commercial_workload_low_pct` of the median of that month's active readers". **R11 workload:** visits summed over the complete months, same median rule, bands low / normal / high / inactive.
- **R12 outliers:** z-score of each eligible reader's skip rate over the complete months (population standard deviation). "At least the minimum visits" is applied **per active month** (total visits ÷ months with visits), not to the range total, so a reader of about 160 visits a month never qualifies by adding months (this matches the planted case 90005 in the sample pack); for a one-month range it is simply "at least N visits"; flagged above `commercial_outlier_zscore`. With n eligible readers the largest possible z is sqrt(n-1), so a flag needs at least 6 eligible readers at the default threshold of 2.
- **R13 scorecard:** weighted percentile rank (share of the other readers beaten, a tie counting half; a lone reader scores 100). If a reader's consistency is unknown, the other weights are scaled up for them. Weights are shown on screen and the score is labelled "indicative".
- **Coverage** uses the region-wide verified strength, so it is hidden while a district filter is on (the district filter is the reader's *home* district).
- **Access:** the Reading page opens with either reading permission; a tab the user may not open is never computed and `?tab=` falls back to the first allowed one. The reader page needs `view_reader_performance`: 403 when the reader's rows are only in another region, 404 when unknown (and for the system account). Home shows reading numbers to `view_reading` / `view_dashboard` holders only.
- **Not done / for later:** no exports (Phase 4); no scatter chart; the screens have been exercised in tests only, not yet looked at in a browser against the dummy data in `docs/sample-data/commercial/`.

---

### 8.6 Phase 2 review notes (2026-10-06)

Reviewed against the code: `ReadingAnalyticsService` takes already-scoped result sets and aggregates in PHP (no DB-specific SQL), the `?tab=` fallback is enforced server-side, and the as-built decisions in 8.5 are sensible. Worth keeping in mind: the R12 outlier test cannot fire with fewer than 6 eligible readers (maths, not a bug — a very small region will never show outliers); the screens have so far been tested in code only, so **look at them in a browser against the sample pack before Phase 3** (see 8.7).

### 8.7 Before Phase 3: look at Phase 2 in a browser (10 minutes)

With the sample pack loaded (staff pack → reading Jun–Sep → reading Jul–Oct refresh), open Commercial → Reading as a user holding `view_reader_performance` and check: (1) Readers tab: `SAMPLE READER 01` tops the league table with the lowest skip rate; (2) Exceptions: `02` is the skip-rate outlier, `04` is inactive, `05` (tiny volume, high skip) is **not** an outlier; (3) `03` shows "not enough months" for consistency; (4) `23` and `24` are ranked and flagged "Not in staff directory"; (5) October carries the **In progress** badge and is absent from movement/inactive/outliers; (6) void the refresh batch and Jul–Sep revert to the first upload's figures; (7) as a user with only `view_reading`, the reader tabs are gone and `?tab=readers` falls back to Trend. Report anything off before building more on top.

### 8.8 Phase 3 scope — billing analytics (B1–B12)

All of it needs `commercial.view_billing`. There is no staff data in billing, so no extra permission. Exports stay in Phase 4.

**Rules fixed here so the numbers and screens agree**

- **A billing analysis always works on ONE snapshot** — a single effective batch, i.e. the newest non-voided, non-superseded batch for a (region, customer segment, period). Never sum across snapshots: balances roll forward (opening/closing are not additive) and a multi-month period overlaps single months. The page's first control is the **snapshot picker** (region · segment · period, newest first, defaulting to the latest single-month snapshot, else the latest). Add `BillingAnalyticsService::snapshots(Builder $batches)` returning those effective batches, scoped with `scopeBatchesForActor()`.
- **Multi-month snapshots** (`granularity = multi_month`, e.g. Jun–Aug) are fully usable for B1–B11 on their own, but are **excluded from the trend and the comparison** (B12) and the page says so; a single-month snapshot is the only kind that can be compared or trended.
- **Rates are percentages** rounded to 2 dp (as in Phase 2) and `null` ("–") when the denominator is 0. Never take a ratio from the source; `Collection Ratio` is not stored.
- **Units:** volumes are thousand litres, which is 1 m³ per thousand litres, so "GH¢ per m³" = `billing_for_period ÷ volume_total`.
- **Do not chart or display `customers_count`** ("Number Of Customers") anywhere — its meaning is unconfirmed (§10 Q2) and it does not reconcile with the billed count.
- **Balance sign is an assumption** (§10 Q1): negative = customer credit. Wherever credits are shown, say "negative balances are treated as customer credits (to be confirmed)" in a muted hint — not as fact.
- **Targets** (`commercial_target_collection_pct`) are placeholders, shown as "configured target" only.

**Definitions** (per route; every district/snapshot figure is a sum of route figures, then the ratio is recomputed from the sums — never an average of route ratios)

| # | Definition |
|---|---|
| B1 | district and route ranking by `billing_for_period`, `volume_total`, `billed_total`; Pareto: share of billing carried by the top 10 routes |
| B2 | roll-forward per district: opening + billing + adjustment = receivable; − total payments = closing |
| B3 | cash collection ratio = `total_payments ÷ billing_for_period` |
| B4 | current-period collection = `payment_for_month ÷ billing_for_period`; prior-month share of cash = `prev_month_payment ÷ total_payments` |
| B5 | estimation share by volume = `volume_average ÷ volume_total`; by count = `(billed_average_metered + billed_average_unmetered) ÷ billed_total` |
| B6 | unbilled rate = `unbilled_total ÷ (billed_total + unbilled_total)`; split by the five reasons |
| B7 | credits: sum (absolute) and count of routes with negative closing balance; the 10 largest |
| B8 | revenue per billed customer = `billing ÷ billed_total`; per m³ = `billing ÷ volume_total` |
| B9 | domestic category (611) bands: customers, volume, amount, share of each, GH¢ per m³, amount per customer |
| B10 | billed mix: average-metered / average-unmetered / actual-reading, by count and by volume |
| B11 | route exceptions (below) |
| B12 | compare two **single-month** snapshots of the same region and segment: change in billing, payments, collection ratio, estimation share, unbilled rate, per district; plus the monthly trend when at least 3 single-month snapshots exist |

**Exceptions (B11)** — new config keys (placeholders, to confirm): `commercial_exception_min_customers` (default 3: a route needs at least this many billed customers to be judged on a percentage, so one-customer routes do not flood the list), `commercial_exception_high_unbilled_pct` (25), `commercial_exception_high_estimation_pct` (75), `commercial_exception_credit_amount` (300). Flags: **zero activity** (`volume_total = 0` and `billed_total = 0`), **heavy credit** (closing ≤ −amount), **high unbilled** and **high estimation** (percentage above threshold, minimum customers applied).

### 8.9 Phase 3 kickoff prompt (ready to paste into Claude Code)

```
Implement Phase 3 of the Commercial module (billing analytics, B1–B12) per
docs/commercial-module-design.md sections 4.2 and 8.8. Read first: CLAUDE.md, the whole
design doc (especially 8.5 and 8.8), then the code you will build on —
app/Models/CommercialBillingRoute.php and CommercialBillingBand.php and
CommercialImportBatch.php, app/Services/Commercial/BillingSummaryImportService.php (to see
exactly what is stored), the Phase 2 pieces to copy the shape of:
app/Services/Commercial/ReadingAnalyticsService.php, app/Livewire/Commercial/Reading.php,
Home.php, Concerns/ScopesCommercialByActor.php, the Reading views under
resources/views/livewire/commercial/, tests/Feature/Commercial/CommercialTestCase.php,
ReadingAnalyticsTest.php and ReadingScreensTest.php, the commercial block in routes/web.php,
commercialSidebar() in app/Support/ErpNavigation.php, and config/gwl.php.

FIRST run `php artisan test --filter=Commercial` and report the result before changing
anything. If anything fails, stop and report; do not fix earlier phases silently.

Build:
1. app/Services/Commercial/BillingAnalyticsService.php, same style as
   ReadingAnalyticsService: methods take already-scoped data and return plain
   arrays/collections; aggregate in PHP after one query for the chosen snapshot's routes
   (about 100 rows); no per-row queries; no DB-specific SQL (tests run on SQLite).
   - snapshots(Builder $batches): the effective billing batches (newest non-voided,
     non-superseded per region + customer_segment + period_from + period_to), newest first,
     each with a label and is_single_month flag.
   - One method per analysis in design 8.8: overview (B1 + B8, district and route ranking,
     Pareto top 10), rollForward (B2), collections (B3 + B4), balances (B7 credits, top 10),
     estimationAndUnbilled (B5 + B6 + B10), bands (B9, from commercial_billing_bands of the
     chosen batch), exceptions (B11), compare (B12) and trend (B12, needs >= 3 single-month
     snapshots of the same region + segment, otherwise return an explanatory reason, not
     an empty chart).
   - Definitions exactly as in 8.8: every district and snapshot figure is the SUM of route
     figures with the ratio recomputed from the sums (never an average of route ratios);
     percentages to 2 dp; null when the denominator is 0; GH¢ per m3 = billing / volume_total.
     Never use or display customers_count.
   - Add the four exception config keys to config/gwl.php and .env.example (names and defaults
     in 8.8).
2. Extend ScopesCommercialByActor only if needed (batches are already scoped by
   scopeBatchesForActor(); do not duplicate). Do not change earlier-phase behaviour.
3. Livewire (app/Livewire/Commercial/Billing.php, thin Blade wrapper in
   resources/views/commercial/, template in resources/views/livewire/commercial/):
   - Route commercial.billing, permission view_billing, enforceLivewireModule, and a guard so
     only view_billing holders compute anything. Snapshot picker first (#[Url] state:
     snapshot batch id, tab, district, optional compare-with batch id). If the snapshot in
     the URL is not one the actor may see, fall back to the default; never 403-leak other
     regions' data.
   - Pill tabs (<x-ui.segmented>): Overview, Collections, Balances, Estimation & unbilled,
     Consumption bands, Exceptions, Compare. Use x-ui.stat-tile, x-ui.card, x-ui.table and
     x-ui.chart as the Assets summary and Reading views do (load charts.js with
     @assets/@vite). The Compare tab shows the monthly trend chart when there are >= 3
     single-month snapshots and the reason otherwise; a multi-month snapshot shows a muted
     note that it cannot be compared or trended.
   - Credits and balances show the muted hint "negative balances are treated as customer
     credits (to be confirmed)". The collection target appears only as "configured target".
   - Routes in the route table link to a filtered view (district filter via #[Url]).
   - Home: add billing KPI tiles (billing, payments, collection ratio, unbilled rate) for the
     chosen default snapshot, shown to view_billing / view_dashboard holders, with the
     snapshot period labelled; keep the Phase 2 reading tiles and the latest-data table.
   - Add a "Billing" item to commercialSidebar() visible with view_billing; routes go inside
     the existing flag-guarded commercial group.
4. Clear empty states: no billing batch yet; a snapshot with no domestic band table; fewer
   than 3 single-month snapshots for the trend.
5. Tests under tests/Feature/Commercial/ (plain PHPUnit, RefreshDatabase, reuse
   CommercialTestCase helpers, synthetic fixtures only): hand-computed B1-B10 numbers for a
   small fixture, including the ratio-from-sums rule (a case where average-of-ratios would
   give a different answer); zero denominators return null, no exception; snapshots() picks
   the newest per key and ignores voided and superseded batches; a multi-month snapshot is
   excluded from compare/trend and flagged; compare works only for two single-month
   snapshots of the same region and segment; trend needs 3; credits count negative closing
   balances only; band metrics (GH¢ per m3, shares); exception thresholds, including the
   minimum-customers rule and that a one-customer route is never flagged on a percentage;
   customers_count never appears in the rendered page; a regional user sees only their
   region's snapshots and a hand-edited snapshot id from another region falls back safely; a
   user without view_billing gets 403 on the route and sees no billing tiles on Home; the
   sidebar item follows the permission.
   Run: php artisan test --filter=Commercial.
6. Update docs/commercial-module-design.md with a short "Phase 3 as built" note listing
   decisions the design did not state, and add the new test files to the module notes in
   CLAUDE.md if Phase 2 added them there.
```

**Sample data for Phase 3.** `docs/sample-data/commercial/` has billing files for Jun–Aug (multi-month), Jul, Aug and Sep (single months, same segment and region), enough for the trend (3 single months) and comparison. Upload them in any order; Jun–Aug must appear in the picker but be excluded from trend and compare.

### 8.10 Phase 3 as built (2026-10-06)

`app/Services/Commercial/BillingAnalyticsService.php` holds B1-B12 (`snapshots()`, `defaultSnapshot()`, `overview()`, `rollForward()`, `collections()`, `balances()`, `estimationAndUnbilled()`, `bands()`, `exceptions()`, `compare()`, `trend()`, plus `totals()` / `metrics()` as the shared building blocks); `Livewire\Commercial\Billing` (route `commercial.billing`, `view_billing`) is the screen, and `Home` gained billing KPI tiles for the default snapshot. New sidebar item **Billing**, new config `commercial_exception_*` (env `GWL_COMMERCIAL_EXCEPTION_*`). Tests: `BillingAnalyticsTest` (hand-computed, including the ratio-from-sums case) and `BillingScreensTest`.

Decisions the design did not spell out:

- **Snapshots** are the newest non-voided batch per (region, segment, period_from, period_to), found by grouping, not by trusting the `superseded` label. Newest period first; the default is the latest **single-month** snapshot, else the latest period. The picker only ever offers snapshots of regions the user may see; a snapshot id typed into the URL that is not one of them silently falls back to the default (no 403, so the existence of another region's batch is not revealed).
- **Filters:** the district filter is the district *as the report prints it* (so unmatched districts still work) and applies to every tab except Consumption bands (the band table has no district split) and Compare. The roll-forward (B2) sits on the Overview tab.
- **Rates and ratios:** percentages to 2 dp from sums, never an average of route ratios; GH¢ per customer / per m³ are plain 2-dp numbers. The headline "estimated share" (B5) is by volume (the compare and the trend use it); by count is shown beside it.
- **B10 by volume** can only be split into actual-reading and average (the report has no metered / unmetered volume split); by count it is average-metered / average-unmetered / actual.
- **B11 minimum customers:** a percentage is only judged where at least `commercial_exception_min_customers` customers stand behind it. The base is **billed + unbilled** for the unbilled rate (so a route with 0 billed and 3 unbilled, 100% unbilled, is still caught) and **billed** for the estimated-bill share; this is a deliberate reading of the design's "billed customers", which would hide the worst unbilled routes. Heavy credit is inclusive (`closing <= -amount`); thresholds are strict (`>`). "High change on the prior period" (mentioned in 4.2 but not in 8.8) is not a flag; the Compare tab shows the changes instead.
- **Compare / trend** live on one **Compare** tab. The comparison takes the earlier month as baseline whichever order they are chosen in; the trend uses every single-month snapshot of the chosen snapshot's region and segment (needs 3) and says why when it cannot be drawn. A multi-month snapshot is fully usable on the other tabs and carries a note on Compare.
- **`customers_count`** is stored but never read by any analysis or view (tests assert it never appears).
- **Sample pack:** with the default placeholder thresholds about 60% of the 108 sample routes are flagged on Exceptions (many routes carry deposit-like credits and few customers); expect to tune the four thresholds once the Commercial team confirms them (10 Q5).
- **Not done / for later:** no exports (Phase 4); not yet looked at in a browser.

---

### 8.11 Phase 3 review notes and browser check (2026-10-06)

Reviewed against the code: `BillingAnalyticsService` works on one snapshot at a time, sums routes then recomputes ratios, never touches `customers_count`, uses no DB-specific SQL, and a hand-typed snapshot id from another region falls back to the default without confirming that it exists. The as-built decisions in 8.10 are sensible, including reading "minimum customers" for the unbilled rate against billed + unbilled so the worst unbilled routes are not hidden.

**One thing to act on:** with the placeholder thresholds about 60% of the 108 sample routes are flagged on Exceptions. That is mostly the sample (many small routes carrying deposit-like credits), but real data looks similar, so a list that long is not useful. Do not tune it blindly: the four thresholds need the Commercial team's input (§10 Q5), and Phase 5 makes them editable. Until then treat the Exceptions tab as indicative.

**Browser check (10 minutes)** with the sample pack: upload billing Jun–Aug, Jul, Aug and Sep. (1) The picker offers all four; the default is **Sep** (latest single month). (2) Overview ranks districts and routes; the roll-forward adds up (opening + billing + adjustment − payments = closing). (3) Collections shows the prior-month share of cash. (4) Balances carries the muted "treated as customer credits (to be confirmed)" hint. (5) Compare: Sep against Aug works; the trend chart appears (three single months); choose Jun–Aug and see the note that it cannot be compared. (6) "Number Of Customers" appears **nowhere**. (7) Consumption bands show two bands with GH¢ per m³. (8) As a user with only `view_reading`, Billing is absent from the sidebar and `/commercial/billing` is 403. (9) Void Sep and the picker's default moves to Aug.

### 8.12 Phase 4 scope — combined analyses, month-to-date pace, executive summary and exports

**Honesty rules that apply to everything in this phase** (the two reports are not like for like):

- **Billing is a customer segment; reading is everyone.** The sample billing is *New Service Customers Only*, while the reading report covers the whole region. Any screen that sets one beside the other must say so in a visible note when the billing snapshot's segment is not `all`.
- **District for reading = the reader's home district** from the staff directory. Say so wherever reading is shown by district.
- **No causal language.** Correlation figures are shown only with at least 6 paired months, labelled "indicative, not causal"; below that, show the two series side by side without a coefficient.
- **Snapshot time is the upload time** (`imported_at`), because the reports do not print a run date. Pace and "as of" labels use it and say so.

**C1 — District scorecard.** One row per district for a chosen billing snapshot: billing, payments, cash collection ratio, estimation share, unbilled rate (all from that snapshot, per `BillingAnalyticsService`), and for the same period the reading side from complete months in range: visits, reads, skip rate, active readers and visits per reader, grouped by reader home district. Join by `district_id` where both sides have one, otherwise by the district label as printed; a district present on only one side shows "–" on the other. Coverage is **not** shown per district (strength is region-wide).

**C2 — Estimation vs skip rate (regional).** Monthly pairs where a single-month billing snapshot **and** a complete reading month exist: estimation share by volume next to skip rate, as a two-series chart and a table; a coefficient only with ≥ 6 pairs.

**R14 — Month-to-date pace.** For a month covered by two or more non-voided reading batches (weekly uploads): visits, reads and skip rate **at each snapshot** (by `imported_at`), plus a straight-line projection = visits so far ÷ fraction of the month elapsed at the snapshot, compared with the previous complete month. Label the projection "indicative". Needs the older snapshots, which are retained (nothing is overwritten); voided batches are ignored.

**C3 — Executive summary.** One page: reading headline (latest complete month: visits, skip rate, coverage, each against the configured target), billing headline (default snapshot: billing, payments, cash ratio, estimation share, unbilled rate), counts of exceptions by flag, data freshness (latest batch per report type, months with no reading data, billing periods loaded) and data-quality flags (unmatched readers, unresolved districts, snapshots that are multi-month). No reader names on this page.

**Exports.** One service feeds the screen preview, the Excel file and the PDF, so they cannot disagree (the Letters register and Assets summary pattern). Permission `commercial.export_reports`; any export that contains reader names or per-reader figures additionally needs `commercial.view_reader_performance`. Exports are region-scoped exactly like the screens. Every export writes an `Audit::log` row with the filters and row count.

| Export | Contents | Extra permission |
|---|---|---|
| Executive summary (PDF + Excel) | C3 | none |
| Reading — region trend (Excel) | R1–R5 by month | `view_reading` |
| Reading — readers (Excel + PDF) | R6–R13 tables | `view_reader_performance` |
| Billing snapshot (Excel workbook, PDF summary) | one sheet per tab: Overview, Routes, Collections, Balances, Estimation & unbilled, Bands, Exceptions | `view_billing` |
| District scorecard (Excel + PDF) | C1 with the approximation note | `view_billing` and `view_reading` |

Spreadsheet-formula injection: reader names, district labels and route codes are text from a file; use a string value binder (`WithCustomValueBinder`) or neutralise a leading `= + - @`, and prove it with a test that reads the generated `.xlsx`. PDF via Dompdf, DejaVu Sans, `isRemoteEnabled = false`, A4 landscape, header with region, period/snapshot, generated time and row count, "Prepared by / Checked by" lines. Row cap `commercial_export_max_rows` (env `GWL_COMMERCIAL_EXPORT_MAX_ROWS`, default 5000).

### 8.13 Phase 4 kickoff prompt (ready to paste into Claude Code)

```
Implement Phase 4 of the Commercial module (combined analyses, month-to-date pace, executive
summary and exports) per docs/commercial-module-design.md sections 4.3 and 8.12. Read first:
CLAUDE.md, the whole design doc (especially 8.5, 8.10 and 8.12), the services and screens to
build on — app/Services/Commercial/ReadingAnalyticsService.php and
BillingAnalyticsService.php, app/Livewire/Commercial/Reading.php, Billing.php, Home.php,
Concerns/ScopesCommercialByActor.php, the commercial block in routes/web.php,
commercialSidebar() in app/Support/ErpNavigation.php, config/gwl.php,
tests/Feature/Commercial/CommercialTestCase.php — and the EXPORT patterns to copy exactly:
app/Exports/Assets/AssetSummaryExport.php and its Sheets/ folder, the assets.summary.export.*
routes and the controller behind them, app/Http/Controllers/Visitors/VisitorExportController.php
(Dompdf), app/Exports/Leave/ApprovedLeavesExport.php, and the Letters register export
(app/Services/Letters/LetterRegisterService.php and its controller/export classes, including the
string value binder and the test that proves "=1+1" is stored as text).

FIRST run `php artisan test --filter=Commercial` and report the result before changing
anything. If anything fails, stop and report; do not fix earlier phases silently.

Build:
1. app/Services/Commercial/CommercialInsightsService.php (reuse the two analytics services;
   do not re-implement their maths):
   - districtScorecard(snapshot, readingStats, strengths-not-needed): C1 per design 8.12,
     joining billing district to reader home district by district_id where both exist, else by
     the district label as printed; a side with no data shows null.
   - estimationVsSkip(...): C2 monthly pairs (single-month billing snapshot + complete reading
     month); a Pearson coefficient only when there are at least 6 pairs, else null with the
     reason.
   - monthToDatePace(readingBatches/stats, month): R14 — per snapshot (by imported_at) the
     visits, reads, skip rate, the straight-line projection = visits so far / fraction of the
     month elapsed at imported_at, and the comparison with the previous complete month. Ignore
     voided batches; include superseded/older batches (their rows are retained).
   - executiveSummary(...): C3 per design 8.12, no reader names.
   Never divide by zero (null); percentages to 2 dp as in earlier phases; no DB-specific SQL.
2. Livewire/screens (thin wrappers + templates, following Reading and Billing): a "Summary"
   page (route commercial.summary; needs view_dashboard OR view_billing OR view_reading, and
   each section is computed only for the permissions the user holds) showing C3 plus the C1
   scorecard and C2 chart (C1/C2 need both view_billing and view_reading), and an R14 pace card
   on the Reading page's Trend tab for months with >= 2 snapshots. Required visible notes:
   whenever billing is shown beside reading and the billing snapshot's segment is not 'all',
   "Billing covers <segment> customers only; reading covers all customers — not like for like";
   wherever reading is grouped by district, "by the reader's home district"; on projections,
   "indicative"; on snapshot times, "upload time". Sidebar item "Summary" (commercialSidebar,
   visible with any of the three permissions).
3. Exports per the table in design 8.12, all fed by the same service methods as the screens:
   export classes under app/Exports/Commercial/ (FromCollection/FromArray + WithHeadings +
   ShouldAutoSize, one sheet per tab for the billing workbook, modelled on AssetSummaryExport),
   a controller app/Http/Controllers/Commercial/CommercialExportController with excel() and
   pdf() actions per export, Blade PDF views under resources/views/commercial/exports/ (Dompdf,
   DejaVu Sans, isRemoteEnabled=false, A4 landscape, header with region, period/snapshot,
   generated time, row count, "Prepared by / Checked by" lines), routes inside the existing
   flag-guarded commercial group with middleware permission:commercial.export_reports, and an
   in-controller check of the extra permission from the table (403 otherwise). Region scoping
   exactly as on screen. Use a string value binder (WithCustomValueBinder) so text starting
   with = + - @ is stored as text. Add commercial_export_max_rows (env
   GWL_COMMERCIAL_EXPORT_MAX_ROWS, default 5000) to config/gwl.php and .env.example; over the cap
   return a clear "narrow the filters" message. Filenames like
   commercial_billing_<region>_<period>_<yyyymmdd>.xlsx. Add Excel/PDF buttons to the Reading,
   Billing and Summary pages shown only to users who hold the needed permissions. Audit each
   export with Audit::log (filters, row count, report name).
4. Empty states: no data for a section, fewer than 6 pairs for C2, a month with a single
   snapshot for R14.
5. Tests under tests/Feature/Commercial/ (plain PHPUnit, RefreshDatabase, reuse
   CommercialTestCase, synthetic fixtures only): C1 join by district id and by label, one-sided
   districts show null; C2 pair selection and the coefficient only at >= 6 pairs; R14
   projection and previous-month comparison hand-computed, voided snapshot ignored; executive
   summary contains no reader names; the not-like-for-like note appears when the segment is
   not 'all' and is absent when it is; each export downloads for a permitted user and is 403
   without export_reports; a reader-level export is 403 without view_reader_performance; an
   export is region-scoped (a regional user's file contains only their region); an .xlsx cell
   holding "=1+1" (reader name / route code) is read back as the text "=1+1"; the row cap
   returns the message instead of a file; every export writes an audit row; PDFs start with
   %PDF; the sidebar item follows the permissions. Run: php artisan test --filter=Commercial.
6. Update docs/commercial-module-design.md with a short "Phase 4 as built" note listing
   decisions the design did not state.
```

### 8.14 Phase 4 as built (2026-10-06)

New: `CommercialInsightsService` (C1 `districtScorecard()`, C2 `estimationVsSkip()`, R14 `monthToDatePace()`, C3 `executiveSummary()`, `freshness()`), `CommercialReportData` (gathers the scoped rows for the Summary page and every export, using the same `ScopesCommercialByActor` trait as the screens), `CommercialExportService` (turns those payloads into titled tables), `Exports\Commercial\CommercialReportExport` + `Sheets\ReportSheet` (Excel; a string value binder), `CommercialExportController` (one route, `commercial.export`, `/commercial/export/{report}/{format}`), `resources/views/commercial/exports/report-pdf.blade.php` (Dompdf, A4 landscape) and the `Livewire\Commercial\Summary` page (route `commercial.summary`, sidebar **Summary**). The Reading Trend tab gained the R14 pace card; Reading, Billing and Summary got Excel / PDF buttons. New config: `commercial_export_max_rows` (env `GWL_COMMERCIAL_EXPORT_MAX_ROWS`, 5000). Tests: `CommercialInsightsTest`, `CommercialExportsTest`, `SummaryAndPaceScreensTest`.

Decisions the design did not spell out:

- **One source of truth.** The scoped, filtered reading queries moved into `ScopesCommercialByActor` (`filteredReadingStats()`, `filteredStrengths()`, `chooseSnapshot()`); the Reading and Billing screens, the Summary and the exports all use them, so a file always matches the page. (A small refactor of Phase 2/3 code; behaviour unchanged, their tests untouched.)
- **Exports:** `report` is one of `summary`, `reading-trend` (Excel only), `readers`, `billing`, `scorecard`. Besides `commercial.export_reports` (route middleware) each needs the permission in design 8.12, checked in the controller (403): `readers` needs `view_reader_performance`; `scorecard` needs `view_billing` AND `view_reading`; `summary` needs any of `view_dashboard` / `view_billing` / `view_reading` and computes only the sections the user may see. Filters come from the page's query string (region only honoured for users who see every region; a snapshot id from another region falls back to the user's default).
- **Workbook shape:** a **Notes** sheet first (report, region, snapshot / period, generated time, row count and every caveat) then one sheet per table. The billing workbook has an extra **Balance roll-forward** sheet (B2) beyond the design's list. The PDF carries the same tables, the header, the notes and "Prepared by / Checked by" lines.
- **Row cap** counts every table of a file together. Over it the user is redirected back with an `error` flash ("Narrow the filters ..."); no file and no audit row. The shared ERP layout only showed `status` flashes, so it now also shows `error` (a small addition that also surfaces the existing module-access redirect message).
- **Audit:** `commercial.export_<report>_<excel|pdf>` with the report name, format, filters and row count.
- **C1 reading side** = the complete months that fall inside the billing snapshot's period (none yet -> the reading columns stay empty and the page says so). A billing district joins to reading by `district_id` where billing has one, else by the district label as printed (case and spacing ignored); unmatched readers form a "No home district" row.
- **C2** pairs a single-month billing snapshot of the chosen region and segment with the complete reading month of the same month (estimation share by volume against the region-wide skip rate). Pearson only from 6 pairs, always labelled indicative, not causal.
- **R14** shows the three newest months that have two or more non-voided reading uploads (the Trend tab's month filters apply). The fraction of the month elapsed at an upload is `(upload time - month start) / month length`, capped to 0..1: before the month began there is no projection, after it ended the projection is the actual figure. Each upload is taken as it is: a later upload that covers fewer readers shows fewer visits. Replaced uploads are kept (their rows are retained); voided ones are left out.
- **Summary:** the quality and freshness sections show to everyone who may open the page; "months missing" lists months between the first and last month any reading upload covers that no upload covers. Counts only, no reader names anywhere on it (and tests check the export too).
- **Not done / for later:** the PDFs have been checked structurally (valid A4 landscape, DejaVu Sans, no remote resources) but not looked at as rendered pages, and none of the Phase 2-4 screens has been looked at in a browser yet.

### 8.15 Phase 5 as built (2026-10-06)

The design had no Phase 5 prompt, only the §5 line ("targets/thresholds screen (super_admin), a scheduled reminder when no batch of a given type has arrived within N days, optional notification to officers") and §4.4 ("move to a `commercial_targets` table + settings screen"). That is what was built.

New: migration `2026_10_06_000001_create_commercial_settings_tables` (**run `php artisan migrate`**; tables `commercial_settings` and `commercial_reminder_states`), `CommercialSettings` (service), `Livewire\Commercial\Settings` (route `commercial.settings`, permission `commercial.manage_settings`, sidebar **Settings**), `UploadReminderService`, the `commercial:remind-uploads` command (scheduled daily at 07:45 while the module is on; `--dry-run` lists without sending), models `CommercialSetting` / `CommercialReminderState`, and an overdue banner on the Uploads page, an overdue alert on the Summary freshness card and an `OVERDUE` row in the summary export. New config: `commercial_reminders_enabled`, `commercial_reminder_reading_days` (10), `commercial_reminder_billing_days` (35), `commercial_reminder_repeat_days` (7); all env-backed and in `.env.example`. Tests: `CommercialSettingsTest`, `UploadReminderTest`.

Decisions the design did not spell out:

- **Settings are overrides of config, laid over it at boot.** `config/gwl.php` (so `.env`) stays the default; an edited value is a row in `commercial_settings`, applied by `CommercialSettings::applyToConfig()` from `AppServiceProvider::boot()` (only while the module is on; quiet before the table exists) and again after every save. Every analysis, screen and export keeps reading `config('gwl.commercial_*')`, so none of them was touched. The overrides are cached (`commercial_settings:v1`) and the cache is cleared on save.
- **A value saved equal to its default is stored as no override**, so "default" keeps meaning "whatever `.env` says" and a later `.env` change still takes effect for settings nobody edited. **Reset** (per group or everything) deletes the rows.
- **What is editable:** the three targets, the reader thresholds (minimum visits, outlier z-score, low / high workload), the three scorecard weights, the four exception thresholds, the reminder settings and the export row cap. Not editable (deployment, not business rules): the module flag, the upload size limit and the Excel limits. Limits per setting are in `CommercialSettings::definitions()`; the low workload must stay below the high one and the weights must add up to 1 (within 0.01).
- **Access:** `commercial.manage_settings` is held by super_admin only, as the seed migration already decided (design 2.1: "proposed, to confirm"). To let another role edit, grant that permission on the UAC roles screen.
- **Audit:** `commercial.settings_changed` (each changed setting with its old and new value) and `commercial.settings_reset`; nothing is written when nothing changed. The screen shows who last changed each value and when.
- **Reminder rule:** per region and report type, over the non-voided uploads: overdue when the latest one is older than the limit (`imported_at`, the upload time); a region that never uploaded a type is not "late" for it. The limits are placeholders until the real cadence is known (10 question 9).
- **Who is told:** in-app notifications (`GeneralDatabaseNotification`, module `commercial`; no email) to active users holding `commercial.upload_reports` whose own region is the late one, plus those who see every region (Head Office staff, Global Admin). The developer `super_admin` account is never told. Reminders can be switched off in the settings; the overdue banner and badge still show.
- **Not every day:** a reminder is repeated at most every `commercial_reminder_repeat_days` for the same overdue upload; a fresh upload that goes late again is reminded straight away (`commercial_reminder_states` remembers the last reminder per region and type).
- **Needs the scheduler:** like MDM, the reminders only run if `php artisan schedule:work` (or a per-minute cron / Task Scheduler entry) is running.
- **Not done / for later:** no per-region thresholds; no email reminders; the Phase 2-5 screens, the PDFs and the settings screen have not been looked at in a browser; the actual targets, thresholds and report cadence still wait on the Commercial team (section 10).

---

### 8.16 Phase 4 and Phase 5 review notes (2026-10-06)

Reviewed by reading the code and tests, not by running them.

**Phase 4 (exports, summary, scorecard): good, with gaps.** One source of truth (`CommercialReportData` feeds the page and every file); the permission matrix is right and tested (readers need `view_reader_performance`, scorecard needs billing AND reading, reading-trend is Excel only, hand-edited region or snapshot ids never widen a file); spreadsheet formulas stay text and numbers stay numbers (read in the vendor `StringValueBinder`); no reader names on the summary; every export audited; over the cap gives a message, no file. Gaps:

1. **Small-group exposure.** The district scorecard (and the Reading Trend district filter) needs only `view_billing` + `view_reading`. In a district with one active reader, that row's visits, skip rate and visits per reader ARE that person's figures.
2. The export test casts to `int` before comparing, so it does not prove numbers stay numeric.
3. A 5,000-row cap is fine for Excel; Dompdf will be slow or run out of memory long before that.
4. `canSeeBillingNumbers()` allows `view_dashboard` as well as `view_billing`; check the Commercial Home gates the same way.

**Phase 5 as built (8.15): the settings and reminders parts are good.** Settings are overrides laid over `config('gwl.*')`, so no analysis code changed; a value saved equal to its default is stored as no override; saving, resetting and reminders are audited and tested; the reminder rule is pure and shared with the Summary badge, so page and notification agree; deduplication is per region and report type with a `commercial_reminder_states` table; the dry run writes nothing; recipients are the region's uploaders plus all-region users, never the developer super_admin.

**But Phase 5 was built without its prompt.** Code's note says "the design had no Phase 5 prompt": the copy of this document in the repo had lost the section that held it (a later save of the file replaced the whole file). So the planned **small-group protection (gap 1), PDF row cap (gap 3), numeric-cell test (gap 2), dashboard rule (gap 4)** and the **live exception preview** were not done. They are in the Phase 5b prompt below. (Lesson, now a rule in the prompt: when Code updates this document it re-reads it first and changes only its own section.)

Two things in the Phase 5 code to fix in 5b:

5. **`CommercialSettings::default()` reads `config/gwl.php` with `require`.** When the server runs `php artisan config:cache` (the normal production setup), `.env` is not loaded and `env()` returns null outside the cached config, so `default()` returns the hard-coded fallback instead of the value `.env` gave. Effects: the Settings screen shows the wrong "Default" and the wrong value for an unedited setting, and saving a value equal to the real `.env` default is stored as an override of a different default. Fix: take a copy of `config('gwl')` the first time `applyToConfig()` runs (before any override is laid over it) and use that copy as the default.
6. **The export row cap and the scorecard weights are editable** (the prompt said they should not be). Harmless for the weights; for the cap it means an administrator can raise it to 100,000 rows and Dompdf will fall over. The separate PDF cap in 5b has its own, low, maximum.

**Browser check for Phase 5 (10 minutes, after `php artisan migrate`):** (1) Sign in as `super_admin`: **Settings** appears under Commercial, cards for Targets, Reader analysis, Scorecard weights, Billing route exceptions, Upload reminders, Exports; each field shows its default. (2) Raise "High unbilled above" to 60, Save: the Exceptions tab flags fewer routes; the toast says 1 setting saved. (3) Enter 120 for a percentage: nothing is saved and the field is highlighted. (4) Reset the group: values return. (5) Sign in as a user without the permission: no Settings item, address gives 403. (6) With the sample reading data uploaded more than 10 days ago in your local database (or temporarily set the reading limit to 1 day), the Summary freshness card and the Uploads page show an overdue notice; run `php artisan commercial:remind-uploads --dry-run` and check the text says who would be told; run it without `--dry-run` and look at the bell for an uploader in that region. Run it again at once: nothing new.

### 8.17 Phase 5b scope — finish Phase 5

| Part | What |
|---|---|
| A | Small-group protection for district-level reading figures (new setting `commercial_min_readers_for_district_figures`, default 3) |
| B | `CommercialSettings::default()` takes its defaults from a copy of the config made before overrides, not from a `require` of `config/gwl.php` |
| C | Live preview of how many routes the exception thresholds would flag; a separate, lower PDF row cap |
| D | Numeric-cell test; dashboard permission rule |
| E | Run the tests and report; do not overwrite this document |

### 8.18 Phase 5b kickoff prompt (ready to paste into Claude Code)

```
You are finishing Phase 5 of the Commercial module in this Laravel ERP. Phases 1 to 5 are built. A first version of Phase 5 (settings screen, upload reminders) exists - see docs/commercial-module-design.md section 8.15 - but it was built without its full plan, so several planned items are missing and two things need fixing. This is Phase 5b.

FIRST READ: CLAUDE.md, APP_DOCUMENTATION.md, then docs/commercial-module-design.md sections 2, 4.4, 8.14, 8.15, 8.16, 8.17 and this prompt (8.18). Follow CLAUDE.md for layering, authorization, audit and migrations. Never edit an existing migration. Do not commit; leave changes in the working tree.

RULE ABOUT THE DESIGN DOCUMENT: when you add your note at the end, re-read docs/commercial-module-design.md first and change ONLY your own section (add section 8.19 "Phase 5b as built"). Never write the whole file back from an earlier copy: a previous save replaced sections that other people had added.

Do the parts in order, running the Commercial tests after each.

PART A - small-group protection (a privacy fix)
Problem: the district scorecard (C1) and the Reading Trend district filter need only view_billing and view_reading, not view_reader_performance. In a district with one active reader, that row's visits, reads, skip rate and visits per reader are that one person's individual figures.
1. Add the setting commercial_min_readers_for_district_figures: config key in config/gwl.php, env GWL_COMMERCIAL_MIN_READERS_FOR_DISTRICT_FIGURES, default 3, a line in .env.example, and an entry in CommercialSettings::definitions() (group 'readers', int, min 1, max 50, unit 'readers', label "Minimum readers before a district's reading figures are shown", help "Users who may not see individual readers see no district reading figures below this."). The existing test that checks every definition has a config default and an .env.example entry must still pass.
2. For a user WITHOUT commercial.view_reader_performance, any reading figure grouped by home district is hidden when fewer than that many DISTINCT readers had visits greater than 0 in the months shown. Applies to: every C1 scorecard row including the "No home district" row; the Reading Trend tab when a district filter is chosen; and the Excel files of both (the scorecard export and the reading-trend export). Billing columns on the scorecard are NOT affected (no staff in them). On the scorecard show "-" in the reading columns and a small badge "Fewer than N readers". On the Reading Trend with a district filter show an empty state saying the district has too few readers to show without reader-level access.
3. Users WITH view_reader_performance see everything as now.
4. Implement it in the services, not in Blade: pass a boolean (for example $hideSmallGroups) into CommercialInsightsService::districtScorecard() and into the reading-trend path used by CommercialReportData::readingTrend() and the Reading screen, decided in CommercialReportData / the ScopesCommercialByActor trait from the actor's permission. The page and the exports must not be able to disagree.
5. Tests: one reader in a district -> hidden on screen and in the Excel file for a user without the permission, shown with it; three readers -> shown to both; the No home district row follows the same rule; billing columns still shown when reading is hidden; the threshold follows the setting.

PART B - defaults must not depend on .env being loaded
Problem: CommercialSettings::default() does `require config_path('gwl.php')`. With `php artisan config:cache`, env() outside config files returns null, so default() returns the hard-coded fallback instead of the .env value. The Settings screen then shows the wrong default, and a value saved equal to the true default is wrongly stored as an override.
1. Replace it: the first time applyToConfig() runs (called from AppServiceProvider::boot before any override is laid over the config), keep a copy of config('gwl') for the editable keys as the "pristine" values; default() returns from that copy (falling back to the live config value when the copy has not been taken yet, never to a file require). Make sure refresh() and reset() still behave: after a reset the config holds the pristine value.
2. Test: set config(['gwl.commercial_target_skip_rate_pct' => 8.0]) (a value different from the file's hard-coded fallback of 10) before the pristine copy is taken, and show default() returns 8.0, that saving 8.0 stores no override, that saving 9 stores an override and reset returns to 8.0. Provide a way to clear the pristine copy between tests (for example a method on the service or a container singleton re-created per test).
3. In the as-built note add one line for deployment: after changing .env on a server that uses config:cache, run php artisan config:cache again.

PART C - exception preview and PDF cap
1. If BillingAnalyticsService::exceptions() can take the thresholds as an argument (an optional array whose keys default to the configured values) without contortions, use that to show, under "Billing route exceptions" on the Settings screen, a live line that updates as the administrator types: "With these values X of Y routes in the latest billing snapshot would be flagged (now: Z)." Use the default snapshot of the actor's own scope. This is the point of the screen: with the placeholder thresholds about 60% of the sample routes are flagged, which makes the Exceptions tab meaningless, and the Commercial team needs to see the effect while they tune. If it cannot be done cleanly, skip it and say why in the as-built note.
2. Add config commercial_export_pdf_max_rows (env GWL_COMMERCIAL_EXPORT_PDF_MAX_ROWS, default 1500, line in .env.example) and a definition in CommercialSettings (group 'exports', int, min 50, max 3000, unit 'rows'). It applies to PDF exports only; Excel keeps commercial_export_max_rows. Same behaviour when exceeded: redirect back with an error naming the limit that applied, no file, no audit row. Test it for a PDF over the PDF cap but under the Excel cap.
3. Tighten the Excel cap definition's maximum from 100000 to 20000.

PART D - two clean-ups from the Phase 4 review
1. Add a test that numbers stay numbers in the Excel files: for example on the billing Routes sheet the billing and customers cells have a numeric data type (PhpSpreadsheet DataType::TYPE_NUMERIC) while a route code that starts with = is a string.
2. CommercialReportData::canSeeBillingNumbers() and canSeeReadingNumbers() allow view_dashboard as well as view_billing / view_reading. Check how the Commercial Home gates its billing and reading tiles. If the two differ, make them use the same rule (change the one that is wrong and say which and why in the as-built note). Add a test for a user holding only view_dashboard.

WHEN FINISHED
- Run php artisan config:clear, then php artisan test --filter=Commercial, and report the result with the number of tests and assertions. Say plainly if anything could not be run. (Tests use in-memory SQLite, not the local database.)
- Add "8.19 Phase 5b as built" to docs/commercial-module-design.md as described in the rule above: decisions the design did not state, what was skipped (for example the preview) and anything you are unsure about.
- Do not change any real data and do not commit.
```

### 8.19 Phase 5b as built (2026-10-06)

Tests: `php artisan config:clear` then `php artisan test --filter=Commercial`: **210 passed, 1,701 assertions** (192 / 1,572 before 5b). The whole suite also ran: **1,511 passed, 8,390 assertions**. Tests use in-memory SQLite. Nothing could not be run, except that the PDFs and the new Settings preview line have still not been looked at in a browser or PDF viewer (only structure and rendered HTML are tested). Nothing was committed and no real data was touched (the settings tables from Phase 5 still need `php artisan migrate` on the local database).

**Part A, small-group protection**
- Setting `commercial_min_readers_for_district_figures` (config, `.env.example`, definition in group 'readers', 1-50, default 3). It is decided in the trait (`hideSmallGroups()`: a user without `view_reader_performance`) and handed to the services, so the pages and the exports cannot disagree.
- A district's reading figures (visits, reads, skip rate, visits per reader, active readers) are blanked when fewer than N **distinct readers with visits > 0 in the months shown** worked there; the "No home district" row follows the same rule. Billing columns are untouched. The scorecard shows "-" and a badge "Fewer than N readers"; Reading Trend with a district filter shows an empty state; both Excel files carry a note and the scorecard export has a "Reading figures" column that says "Fewer than N readers" on a hidden row. `view_reader_performance` holders see everything.
- Decision the design did not state: the count is of readers active in the months **shown**, not in the whole history.

**Part B, defaults without a file `require`**
- `CommercialSettings` keeps a pristine copy of the editable `gwl.*` keys, taken before any override is laid over the config (by `applyToConfig()`, `refresh()`, or lazily by `default()` when nothing has been laid over yet). `default()` never reads `config/gwl.php`. The service is a container singleton (`AppServiceProvider::register()`), and `forgetPristine()` clears the copy for tests. After a reset the config holds the pristine value.
- **Deployment:** after changing `.env` on a server that uses `config:cache`, run `php artisan config:cache` again (defaults and overrides are both read from the cached config).

**Part C, preview and PDF cap**
- **The live preview was feasible and is built.** `BillingAnalyticsService::exceptions($routes, ?array $thresholds)` takes optional what-if thresholds (any key left out is the configured one). Under "Billing route exceptions" the Settings screen shows "With these values X of Y routes in the latest billing snapshot (label) would be flagged (now: Z). Nothing changes until you save.", updating as the four threshold fields are typed (`wire:model.live.debounce.400ms`). It uses the default snapshot of the actor's own scope (`CommercialReportData::snapshot()` / new `snapshotRoutes()`). A value that is empty, not a number, out of range or a fraction for a whole-number setting is ignored by the preview (the one in force is used); validation on Save is unchanged. With no billing report it says so. Nothing is saved or audited by the preview.
- `commercial_export_pdf_max_rows` (config, env, `.env.example`, definition group 'exports', 50-3000, default 1500). PDF exports use it, Excel keeps `commercial_export_max_rows`. Over the cap: redirect back with a message naming the limit that applied (and suggesting Excel when the rows fit there), no file, no audit row. The Excel cap's maximum is now 20,000 (a value already saved above that would fail validation on the next save; none exists yet).

**Part D**
- Numeric-cell test: on the billing Routes sheet the billing cells are `TYPE_NUMERIC` and the route codes are strings, and a route code starting with `=` stays a string, not a formula.
- **Dashboard rule: no code change needed.** The Home gates its reading tiles on `view_reading || view_dashboard` and its billing tiles on `view_billing || view_dashboard`, which is exactly `canSeeReadingNumbers()` / `canSeeBillingNumbers()`; the Summary uses the same rule. The only difference is the *links*: Home links tiles to the Billing / Reading pages only for `view_billing` / `view_reading`(or reader performance), so a dashboard-only user sees the numbers but no link to pages they cannot open. A test pins this for a user holding only `view_dashboard` (sees the tiles on Home and Summary; Billing and Reading pages are 403).

**Still open / unsure**
- The Phase 4 review points on scorecard weights (must add to 1, tested) and on the export caps are covered above; per-region thresholds and queued parsing remain "later wishes" (section 8b).
- Browser and PDF checks (the lists in 8.15 and 8.16) are still to be done by a person; the new things to look at are the preview line on Settings and the "Fewer than N readers" badge on the Summary scorecard.

### 8.20 Phase 6 — Customer List (built; details in `docs/15-module-commercial-customer-list.md`)

A third upload, **`rptCustomerDetails`** (the customer list, one district per file, weekly or monthly), with its own analytics. It is the first Commercial report that holds personal data and the first that is far too big for the existing importer (millions of customers), so it does **not** use `ReportFileReader` / `DataImportService`: a streaming reader, a staging table and a set-based merge keep memory flat; the customers table holds the CURRENT state (an unchanged row is never rewritten), a change log and an undo table replace per-upload snapshots, and every dashboard reads pre-aggregated rollups. Behind `GWL_COMMERCIAL_CUSTOMER_LIST_ENABLED`; two new permissions (`view_customer_analytics`, `view_customer_details`); a benchmark command proves the speed on MySQL.

This changes section 9: "per-customer analysis" is no longer out of scope **for the customer list** (billing and reading remain as described). The decisions the brief did not state (production DB, the as-of date, the meaning of arrears age, the unconfirmed status codes, who gets the details permission, the rollback rule) are listed as *assumptions* in section 2 of that document for confirmation by the Commercial team.

### 8.21 Phase 6b — customers with more than one mobile number (review and prompt)

**What the sample file shows** (structure only; no value is quoted here). In `rptCustomerDetails` the `Mobile` column (header cell `N10`, one column, not merged) holds each number as a 12-digit text in international form without a plus (`233` followed by nine digits). A customer with two numbers has both in the one cell, joined by space, slash, space (`<12 digits> / <12 digits>`). Other cells in the same row are merged and the value sits in the left cell of the merge: name (`D:E`), address (`I:M`), e-mail (`O:P`), last read date (`R:S`). Dates arrive as date-times with a time part, sometimes with microseconds.

**What the built importer already does** (read in `CustomerValues::phones()` and its tests, not run). It splits a cell on `/`, `,`, `;`, `|` and new lines; strips everything that is not a digit; repairs `233...`, `+233...` and a lost leading zero to the 10-digit local form `0XXXXXXXXX`; drops repeats; keeps the valid numbers in order as a comma-joined list in `commercial_customer_contacts.mobiles`, the first one in `phone_primary`; flags the customer `INVALID_PHONE` when any token is bad (the good ones are kept) and `MISSING_MOBILE` when none is good. A test covers `"0241111111 / +233 20 222 2222"`. The single-customer view shows every number, masked. So a `/`-separated cell is handled and Phase 6 does not need a rebuild. Gaps found:

1. **Only the first number can be found.** Search by phone matches `phone_primary` only, and the "shared mobile" duplicate list groups on `phone_primary` only. A landlord or agent number that is somebody's SECOND number is invisible to both.
2. **Lists and exports show only the first number**, with no sign that a second one exists.
3. **Two numbers with no slash are both lost.** `0241234567 0207654321`, `...&...` or `... and ...` have the non-digits stripped, giving 20 digits, which is "invalid", so neither good number is kept.
4. **Junk numbers pass.** Any `0` plus nine digits is accepted, including `0000000000`; one placeholder shared by hundreds of accounts would flood the shared-mobile list.
5. **No numbers about it.** Nothing reports how many customers have two numbers, or how many can be reached at all.
6. **97 invalid tokens in the real sample are unexplained.** Nobody has looked at their shapes.

### 8.22 Phase 6b kickoff prompt (ready to paste into Claude Code)

```
You are improving how the Commercial "Customer List" import (Phase 6, docs/15-module-commercial-customer-list.md) handles customers who have MORE THAN ONE mobile number in the Mobile cell. This is Phase 6b.

FIRST READ: CLAUDE.md, APP_DOCUMENTATION.md, docs/15-module-commercial-customer-list.md, and docs/commercial-module-design.md sections 8.20 to 8.22. Then read the code: CustomerValues::phones() and maskPhone(), CustomerRecordBuilder, CustomerMergeService, CustomerListService (search and the shared_mobile issue), CustomerRollupService (issueAccounts, quality counts), CustomerExportService, and the contacts migration. Never edit an existing migration; add a new one. Do not commit.

RULES
- The data is personal data. Never copy a real phone number, name, address or e-mail into code, tests, docs, logs, audit rows, exception messages or your report. Tests use obviously synthetic numbers built by the existing workbook builder in the Customers tests. When you look at the real sample, print COUNTS and SHAPES only (digits shown as 9, letters as a), never values.
- Everything must stay fast at millions of customers: no per-row queries, set-based SQL, no new index unless a query needs it, and say what each new column or index costs the bulk merge.
- When you add your note to docs/commercial-module-design.md, re-read the file first and change ONLY your own section (add 8.23 "Phase 6b as built"). Never write the whole file back from an earlier copy. Also update docs/15-module-commercial-customer-list.md (mobile handling, new quality counts).

FACTS ABOUT THE FILE (from the sample): the Mobile column holds each number as 12-digit text in the form 233 plus nine digits (no plus sign); a customer with two numbers has them in ONE cell joined by " / " (space, slash, space). The importer must keep handling that, plus the older local form (0 plus nine digits), a lost leading zero (nine digits), +233 and 00233 forms, and a cell that Excel stored as a number.

STEP 1 - MEASURE FIRST (report in the as-built note, aggregates only)
Using the real sample the Phase 6 work was tested on (and the small 3-row sample if it is available), report: how many Mobile cells hold 0, 1, 2, 3 and 4 or more valid numbers (and the maximum); the shapes of the 97 tokens now counted invalid, grouped with counts (for example "99999999999 x 40"); how many of those the new segmentation in step 2 would recover. If the maximum is 2, use a phone_secondary column in step 3; if it is higher, use the phones table.

STEP 2 - PARSING (CustomerValues::phones and its callers)
1. After the existing split, if a token's digits do not form one valid number, try to read it as several numbers back to back: consume from the left a 12-digit number starting 233, else a 10-digit number starting 0, else a 9-digit number, and accept the result only when it uses ALL the digits and only one segmentation is possible (otherwise the token stays invalid). Also split on "&" and on the words AND / OR / NA between numbers, and ignore extension markers (ext, x) and what follows them. A token like "024 123 4567" (spaces INSIDE one number) must still be one number: do not split on plain spaces first.
2. Placeholder numbers are invalid: ten digits where the nine digits after the leading 0 use two or fewer distinct digits (0000000000, 0111111111, 0121212121 ...) or are a straight run (0123456789). They set INVALID_PHONE, are not stored in the phone columns, and never take part in search or the shared-mobile lists.
3. Keep: unique numbers in cell order; the valid ones kept when another token is bad; a numeric Excel cell turned into digits without a decimal point or exponent, a lost leading zero repaired.
4. Count, per batch, in the existing warnings/stats (counts only): cells with 2 or more numbers, numbers recovered by segmentation, placeholders dropped.

STEP 3 - SECOND NUMBERS MUST BE FOUND
Decide by the step-1 maximum: if it is 2, add a nullable phone_secondary char(10) to commercial_customer_contacts with an index; otherwise add a table commercial_customer_phones (customer_id, position tinyint, number char(10), primary key customer_id+position, index on number) and fill it from staging in the same set-based merge, only for customers whose contact_hash changed. Either way:
1. contact_hash already includes the mobiles; make sure a change of ONLY the second number is detected, rewrites only the contact data, and writes NO change-log row (the change log never holds personal data) and does not touch the customers row.
2. Search by phone (CustomerListService) matches ANY of the customer's numbers. The search still needs the details permission.
3. The shared_mobile issue (CustomerRollupService::issueAccounts) counts a number as shared when it appears on more than one account in the district as first OR second number (UNION ALL of the columns, then GROUP BY), excluding placeholders and excluding the same account listing a number twice.
4. The PII purge (retention setting) and the rollback rules treat the new column or table exactly like the other contact data: purged with it, not rolled back.

STEP 4 - SHOW AND EXPORT BOTH
1. Lists: show the first number masked and a small badge "+1" (or "+n") when there are more. No new query per row: carry a count column in the list query.
2. The single-customer view already shows every number masked: keep it, unmasking stays audited (audit rows carry the customer id and the fact, never a number).
3. Exports: the "Mobile (masked)" cell shows every number masked, joined with " / ", as TEXT (the sheet class stores text as text); the aggregate-only exports still carry no phone data.

STEP 5 - NUMBERS FOR THE COMMERCIAL TEAM
1. Add a quality flag bit for "two or more valid numbers" and quality counts: customers with 2 or more numbers, and customers reachable (at least one valid number), per district and for the region, on the Data quality tab and the contact-completeness figure, with a drill-down list like the other issues (capped like the others). Rollups keep being written once per batch by set-based SQL.
2. Show them in the same style as the existing counts (no new screen).

TESTS (PHPUnit, synthetic data; add to the existing Customers tests)
- A cell with two numbers joined by " / ", by "/" with no spaces, three numbers, the same number twice, a trailing or leading slash, an empty segment.
- 12-digit 233, +233, 00233, 10-digit, 9-digit, and an Excel numeric cell (int and float).
- Two numbers separated only by a space, by "&", by "and"; spaces inside one number stay one number; an ambiguous long digit string stays invalid.
- One valid plus one invalid token: the valid one is kept and INVALID_PHONE is set; placeholders are invalid and never searched or listed as shared.
- Search finds a customer by the SECOND number; two accounts sharing a number as first on one and second on the other appear in the shared_mobile list; one account listing the same number twice does not.
- Changing only the second number: the contact row changes, no change-log row, customers row untouched, and a re-import of an unchanged file does zero writes.
- Lists show "+1"; the export cell shows both masked numbers as text; no audit row, log line, exception message or queue payload contains a digit sequence of a phone number.
- The purge removes the new data with the rest; rollback leaves it as before.
- The quality counts (2 or more numbers, reachable) equal the drill-down list sizes and are scoped by region.

FINISH
- Run php artisan config:clear, then php artisan test --filter=Commercial, and report the number of tests and assertions, and the full suite if you can. Say plainly what you could not run.
- Re-run the benchmark command described in docs/15-module-commercial-customer-list.md at a smaller size (say 500,000 customers) and report import time per 100k rows and the shared_mobile and search timings before and after. If you cannot run it on MySQL, say so.
- List the migration to run and any new config or .env keys.
```

### 8.23 Phase 6b as built

**Step 1, measured on the real sample (aggregates only; no value was printed or written anywhere).** The district file of about 18,400 rows (a 3-row sample was also read: two cells with one number, one with two). Of 15,957 non-empty Mobile cells before the change: 6,377 held one valid number, 9,567 held two, 13 held none, and **the maximum was 2**, so the second number got its own column (`phone_secondary`), not a phones table. 97 cells (99 tokens) held a bad token. Their shapes, digits shown as 9: `9999999999999` (13 digits) x49, `99999999999` (11) x40, `999999999999` (12) x4, `99999999999999` (14) x3, `9999999999` (10) x2, `+9999999999999` x1 (counted in the 13-digit group). By prefix: 21 of the 13-digit ones are `233` + the local number with its 0 kept (a stray zero); 28 are `233` + ten digits that do not start with 0; 31 of the 11-digit ones are `233` + eight digits (a digit lost). **The run-together segmentation recovers none of them** (no token holds two numbers back to back, so the missing-separator case from 8.21 does not occur in this file, only in the tests). What does recover: removing the stray zero after `233` repairs 21 tokens. After the change: 6,363 cells hold one valid number, 9,584 hold two, 78 cells still have a bad token (was 97), and one number that looked valid was a placeholder and is now set aside. The other bad tokens (a lost or extra digit) cannot be repaired without guessing and stay invalid and counted.

**What was built.**
- `CustomerValues::readPhones()` is the only reader of a Mobile cell (`phones()` wraps it): separators `/ , ; | &`, line break, AND / OR / NA; `ext` / `x` and what follows ignored; `233`, `+233`, `00233`, a lost leading zero, an Excel number (int or float, no exponent), the stray zero after `233`; back-to-back numbers cut from the left (12 digits `233...`, 10 digits `0...`, 9 digits) and accepted only when exactly ONE cutting uses every digit; placeholders (nine digits after the 0 with two or fewer distinct digits, or a straight run) are invalid and never stored. Spaces inside one number never split it.
- Migration `2026_10_14_000001_add_phone_secondary_to_commercial_customer_contacts` adds a nullable `phone_secondary` (indexed) to the contacts and a plain one to staging. Contact rows get it filled by the normal upload: the contact hash now covers the new `MULTIPLE_PHONES` bit, so exactly the customers with two numbers are rewritten once, contact row only (no customers row, no undo row, no change-log row; a test compares the whole customers table before and after). A re-upload of an unchanged file writes nothing at all, contacts included.
- Search by phone matches either number. The shared-mobile list counts a number as shared when it is on more than one **account** of the district as first or second number (one account listing a number twice does not count; one account in two shared numbers is listed once).
- Lists show the first number masked and `+n`; the count comes from the `mobiles` string in the same query (no query per row). The Excel list export puts every number, masked, in the one "Mobile (masked)" cell as text, joined by ` / `. The single-customer view and its audited reveal are unchanged (audit rows hold the customer id only).
- Quality: new counts "Two or more mobile numbers" (drill-down list capped like the others) and "Reachable" (customers with at least one valid number; its list is the inverse of "No mobile number"), as rows of the Issues table and as two columns of the district table. Each import adds one counts-only warning line (cells with two or more numbers, numbers separated from a run, stray zeros removed, placeholders set aside).
- Purge retention removes `phone_secondary` with its contact row; a void does not roll contact details back (both tested).
- `commercial:customers:sample` (dev only) now writes real-export-style cells, 12 digits, two numbers for every eighth customer and a shared agent number for a few; `commercial:customers:benchmark` seeds and times the same, and compares the old and new statements.

**Tests.** `CustomerPhoneParsingTest` (35 cases, pure) and `CustomerSecondPhoneTest` (12 tests) cover every case in the 8.22 list: two numbers with `" / "`, bare `/`, three, the same twice, trailing/leading slash and empty segment; `233`, `+233`, `00233`, ten and nine digits and Excel int/float; a space, `&`, `and`, `AND`, `or`, `na` between numbers; spaces inside one number; an ambiguous long string stays invalid; a good plus a bad token; placeholders; search by the second number; shared in either position; changing only the second number; `+1` badge; export cell as text; no audit row, warning or error holding a phone number; purge and rollback; counts equal list sizes and are region-scoped. One existing test used `+233 20 222 2222` (a placeholder by the new rule) and now uses a different invented number.

**Run results.** `php artisan config:clear`, then `php artisan test --filter=Commercial`: 347 tests, 2,474 assertions, all passed. Full suite (`php artisan test`): 2,052 tests, 11,672 assertions, all passed (the 47 new tests are included; the suite before this phase had 2,005). Not run: the 5M-customer benchmark again, the new screens in a browser beyond a quick look at the Overview and Trends tabs (the Data quality tab and the lists with the `+n` badge were checked by tests, not by eye).

**Benchmark (MySQL 8.4.3, 128 MB buffer pool, scratch database, 500,000 customers in 10 districts, 50,000-row files with two numbers on every fourth customer).**

| Measure | Result |
|---|---|
| Import, initial load (50k rows) | 107 s per 100k rows (stage 36, merge 42) |
| Import, 10% changed | 73 s per 100k rows (merge 10) |
| Import, unchanged file | 68 s per 100k rows (merge 5); 0 customer rows written |
| Rollup build | 21.9 s per 100k customers (the 2M run: about 21) |
| of which issue lists | 37 s for the 500k (about 7.4 s per 100k; the 2M run: about 6) |
| Shared-mobile statement, one 95k-customer district | **before** (first number only) 965 ms, **after** (first or second number) 1,659 ms: it reads each number once more, and runs once per upload, not per view |
| Shared-mobile drill-down (the list a person opens) | 3 ms |
| Search by phone | **before** (first number only) 1 ms as a bare statement; **after** (first or second, through the list service with its label lookups) 4 ms; by the second number 3 ms |
| Dashboard queries (all) | 3 to 31 ms (target ~300); Data quality 16 ms |
| Drill-down queries | 3 to 11 ms, except 40 keyset pages in a row 766 ms (the existing top-debtors walk; about 19 ms a page) |
| Import memory | flat 60 MB |

A first attempt at the shared-mobile statement (two scans of the contacts) measured 1,802 ms; reading them once brought it to 1,659 ms. The extra cost over "before" is real and is the price of finding second numbers; it is under 1 s per 95k-customer district per upload. The index on `phone_secondary` costs one extra index entry per contact row written; the bulk merge writes contacts only for new accounts and for changed contact hashes, and a NULL second number (about three customers in four) adds a cheap entry. The 5M-customer problems recorded in doc 15 (rollup `connections` step, buffer pool) are not touched by this phase and are still open. The benchmark is a synthetic mix, not the real file.

**To run:** `php artisan migrate` (one migration: `2026_10_14_000001_add_phone_secondary_to_commercial_customer_contacts`). No new config or `.env` keys. Existing contact rows fill in on the next upload of each district (only the customers with two numbers are rewritten). The dev database already has it applied and holds sample data re-imported with two-number cells.

**Not done / to know.** A third number would be kept only in `mobiles` (not searchable); the real sample has none. The 78 remaining bad tokens are lost or extra digits and were deliberately not "repaired". A number in the file that is a real but placeholder-looking one (two distinct digits) will be set aside as invalid, as the brief asked.

---

## 8b. After Phase 5b

Once 5b is done, all planned build work is finished. What remains is input and checking, not code: the Commercial team's answers to section 10 (official targets and thresholds, the real upload cadence, who sees individual readers, whether a district-level reading export exists), a look at every screen and PDF in a browser (the checklists in 8.15 and 8.16), and, when the module is ready to go live, switching `GWL_COMMERCIAL_MODULE_ENABLED` on and starting the scheduler. Later wishes (queued parsing for very large files, email digests, per-region thresholds) are written when wanted, against the real schema.

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
