# CLAUDE.md

Guidance for Claude Code (and any other agent) working in this repository.

## What this is

A Laravel-based internal ERP portal (staff_id + password login) covering seven
modules: **UAC** (user access control), **Staff** (HR directory), **Leave**
(leave requests/balances), **Letters** (document routing), **Visitors**
(kiosk check-in/out), **Assets** (ICT inventory, plus Android Enterprise
phone management — "MDM", behind `GWL_MDM_ENABLED`), and **Transport** (fleet
management). See `APP_DOCUMENTATION.md` and `docs/` for a functional
walkthrough of each module — this file is about how the code is put
together, not what it does for end users.

A **Commercial** module (billing and meter-reading analytics from Excel uploads, behind `GWL_COMMERCIAL_MODULE_ENABLED`) is
being built in phases; Phase 1 (import, reconciliation, batches) is in. Design and decisions: `docs/commercial-module-design.md`.

A **Health & Safety** module (incident reporting that replaces the four Microsoft Forms; later equipment, PPE and alerts, behind
`GWL_HEALTH_SAFETY_MODULE_ENABLED`) is being built in phases; Phase 1 (sites, incident reporting end to end) is in. It is open to
every role (everyone reports), so the permissions decide what each person sees. Design, decisions and what was built:
`docs/health-safety-module-design.md` (section 8.1). Things that are easy to get wrong:
- **What a viewer may see of an incident is decided only by `Services/HealthSafety/IncidentVisibility`** (scope, reporter identity for confidential reports, injury details, findings, timeline notes). The register, detail screen, print copy/PDF, notices and photo route all call it; never filter in Blade. The Livewire `ScopesHealthSafetyByActor` trait (a third separate scoping trait) only wraps it.
- **Every change goes through `IncidentWorkflowService`**, which re-reads the incident `lockForUpdate`, authorises on that copy, writes the `hs_incident_status_logs` row and the audit entry, and fires events after the commit. High/Critical incidents close only through `pending_closure` and an `approve_closure` holder. `IncidentShow` keeps only the incident id and re-checks visibility on every call.
- Notices (`IncidentNotificationService`) reuse `GeneralDatabaseNotification` and never name a reporter.
- **Anonymous reports** (`is_anonymous`): `IncidentWorkflowService::submit()` stores no user, employee, IP or uploader for them (audit row written with no user via `audit()`, photos re-encoded by `withoutMetadata()` and renamed). Keep it that way: anything new that records "who" while a report is filed (logs, notices, timeline) must skip it for an anonymous one. Outside-authority tracking was dropped (its `hs_incidents` columns are unused).
- **Phase 2 (fire extinguishers and first aid kits):** the *computed state* of an item is written once per state as an SQL condition and a PHP test side by side (`states()` in `HsFireExtinguisher` / `HsFirstAidKit`, trait `HasEquipmentState`); never re-derive a state in a screen, a count or an export, and keep conditions NULL-safe because they are negated to exclude earlier states. Lists and the overview counts are built only by `EquipmentExpiryService`. Region/district rules live in `Services/HealthSafety/ActorScope`, shared by `IncidentVisibility` and `EquipmentScope`. Every label naming a user who acted on an incident goes through `IncidentVisibility::nameFor()` (a confidential reporter can also be its owner or closer). Equipment sits at a site OR in a vehicle (enforced in `EquipmentLocation`, no DB CHECK); it copies region/district from its site, and `Sites` save updates them. Checks and services are immutable. Import (`EquipmentImportService`) is create-only, re-reads the file on confirm and never creates a site.
- **Phase 3 (PPE):** `PpeComplianceService` is the ONE evaluator of who is missing PPE (rule in design §8.9: missing > overdue > short > replacement_due > ok); the gaps screen, My PPE and the overview all call it and it deliberately has no SQL twin. Stock is an immutable signed ledger (`hs_ppe_stock_movements`, rules in `PpeStockService`, balance never negative, `lockForUpdate` on the `hs_ppe_types` row); a correction is an `adjustment` row. Issues (`PpeIssueService`) post their ledger row in the same transaction unless `is_historic` ("already held"); a normal issue is dated today, closed issues are immutable, replacing closes the old rows and sets `replaced_by_issue_id`. A PPE store is a site with `is_ppe_store` (normally the regional office, which has no district, so district managers see issues and gaps but no balances); the employee picker is `EquipmentScope::employees()`, `EmployeeDirectory` is untouched. Details: design doc §8.11.
- **Phase 3b (QR labels and site posters):** printed codes hold an ABSOLUTE link built only by `QrLinks` (base `gwl.hs_qr_base_url`, else `APP_URL`; numeric item id, never the asset code), so labels and posters are printed from production only. The only QR code is made in `QrCodeGenerator` (bacon/bacon-qr-code, already an MDM dependency; SVG data URI, Dompdf draws it as vectors, no GD/Imagick). `EquipmentLabelService` / `SitePosterService` scope the items by the actor (outside items are excluded and counted), render first, and stamp `label_printed_at` only after the PDF exists; ticked rows reach the download route through a one-use `LabelBatch` token. `LabelController::scan` answers the same 403 page for a missing item and one outside the person's reach (do not make them differ); `?check=1` opens the existing check form and `ReportIncident` `?site=` only pre-fills. Details: design doc §8.14.

`docs/` is split by module and may drift; the Assets and Transport modules
in particular were added after `APP_DOCUMENTATION.md` was last updated, so
verify against the actual code (routes/models/migrations) before trusting
docs on those two.

## Tech stack

Versions below are what `composer.lock` / `package-lock.json` pin; those files are the source of truth.

- **PHP 8.4.1+** (`composer.json` requires `^8.4.1`; production and local run 8.5, and `config.platform.php` is pinned to `8.5.0` so the lock never assumes a newer PHP than production), **Laravel 13** (13.33) on **Symfony 8.1** components
- **Livewire 4.4** — primary interactive UI layer (not a JSON API + SPA frontend)
- **Laravel Breeze 2.4** — auth scaffolding (login/register/password reset views + controllers)
- **Maatwebsite/Excel 4.0** on **PhpSpreadsheet 5** — xlsx import and export. 4.x interfaces are natively typed: export/import classes need real return types (e.g. `collection(): Collection`), and a class implementing only `WithMultipleSheets` must also implement `Maatwebsite\Excel\Concerns\Export` or `Excel::download()` throws a TypeError.
- **dompdf/dompdf 3.1** (used directly via `new Dompdf($options)`, not the barryvdh wrapper) — PDF export (visitor logs, transport reports, credit union statements)
- **Blade + Alpine.js 3 + Tailwind CSS 4 + Vite 8** (`laravel-vite-plugin` 3) — frontend. Node **22+** is required (`concurrently` 10, used by `composer run dev`).
- **Chart.js 4** (npm, MIT) — the only chart library. It's a separate Vite entry (`resources/js/charts.js`, sets `window.Chart`) that each chart component loads with `@assets @vite('resources/js/charts.js') @endassets`, so it only ships on pages that draw charts; the module runs before `DOMContentLoaded`, so draw charts from that event, not immediately. Don't reintroduce ApexCharts: since v5.1 its licence needs a paid commercial licence for organisations over US$2M revenue.
- **google/apiclient 2.x** (only `Google\Service\AndroidManagement` + `Pubsub`; `composer.json` runs `Google\Task\Composer::cleanup` on `pre-autoload-dump` to delete the other ~670 service folders from `vendor/`, so a fresh `composer install` is slow once and then small), **firebase/php-jwt 7** (verifies Pub/Sub push OIDC tokens) and **bacon/bacon-qr-code 3** (inline-SVG enrollment QR codes) — all MDM only.
- **signature_pad 5** (npm, MIT) — likewise its own Vite entry (`resources/js/signature-pad.js`, sets `window.SignaturePad`), loaded only by the standalone visitor kiosk page through its `@vite([...])` list.
- Front-end libraries come from npm through Vite (version-locked with integrity hashes in `package-lock.json`), never from a CDN `<script>` tag.
- **SQLite** by default (`database/database.sqlite`); swappable via `.env`
- **Pest is NOT used** — tests are plain PHPUnit **13** (`php artisan test` / `phpunit`)

### Tailwind v4 setup (read before touching `resources/css/app.css`)
- There is no `tailwind.config.js`; config lives in CSS (`@theme`, `@plugin '@tailwindcss/forms'`, `@source`, `@custom-variant dark`) at the top of `resources/css/app.css`, and PostCSS uses `@tailwindcss/postcss`.
- Tailwind is imported as three separate files and **utilities are deliberately left unlayered**. `app.css` has ~2.5k lines of unlayered custom CSS (including global `table`/`th`/`td` rules); if utilities sat in `@layer utilities` (the v4 default `@import 'tailwindcss'`), that custom CSS would silently beat every utility, e.g. `px-4` on a `<td>`. Don't "simplify" this back to `@import 'tailwindcss'`.
- The `@layer base` block restores a few v3 behaviours the rest of the UI relies on: default border colour, native `::file-selector-button` and `<option>` padding, and `cursor: pointer` on buttons.
- v4 `space-y-*`/`space-x-*` put margin on the *bottom/end* of earlier siblings (v3 used top/start on later ones), so a child with its own custom `margin-bottom` can shrink the gap; set the margin explicitly when that happens (see `livewire/leave/hr-dashboard.blade.php`).
- `resources/views/welcome.blade.php` inlines its own precompiled Tailwind CSS and isn't routed; don't run class codemods over it.

## Folder structure

```
app/
  Console/Commands/Mdm/      mdm:* Artisan commands (enterprise bootstrap, poll-events, sync-devices, prune-events)
  Enums/                     StaffGrade (the one place the allowed staff grades live)
  Events/Transport/          Domain events (currently transport-only)
  Exports/{Leave,Staff,Transport,Visitors}/   Maatwebsite Excel export classes, one per report
  Http/
    Controllers/             Thin controllers; grouped by module (Leave/, Staff/, Transport/, Visitors/, Assets/, Api/, Auth/)
    Controllers/Concerns/    EnforcesModuleAccess trait for controller-level module checks
    Middleware/              CheckModuleAccess, CheckRole, CheckPermission, EnsureUserIsActive
    Requests/                Form requests, grouped by module (Transport/, Uac/, Auth/)
  Listeners/Transport/       Event listeners (notify managers of issues/maintenance/expiry)
  Jobs/Assets/Mdm/           The only queued jobs in the app (SendMdmCommand, ProcessAndroidNotification, SyncMdmDevice)
  Livewire/                  Interactive UI components, grouped by module (Leave/, Staff/, Letters/, Transport/, Assets/ incl. Assets/Mdm/, Visitors/, Uac/, Notifications/)
  Livewire/Concerns/         EnforcesModuleAccess trait for Livewire-level module checks (separate from the controller one above)
  Mail/                      Mailables (leave submitted/recommended/approved/denied)
  Models/                    Eloquent models, flat under app/Models (Concerns/ holds shared traits like HasUuid)
  Notifications/             Laravel notification classes (invite user, general bell, leave recommended)
  Observers/                 EmployeeObserver (syncs Employee -> User), MileageLogObserver
  Policies/                  VehiclePolicy (the only policy currently defined)
  Providers/                 AppServiceProvider (forces HTTPS scheme, etc.)
  Repositories/Transport/    VehicleRepository (only module using an explicit repository)
  Services/                  Business logic layer, grouped by module: Leave/, Letters/, Staff/, Hr/, Transport/, Commercial/, Import/, plus a flat ReportsService
  Support/                   Cross-cutting helpers: ErpNavigation (nav/module visibility), Audit (audit-log helper), PasswordRules, UserProfilePayload
  View/Components/           Blade layout components (AppLayout, ErpLayout, GuestLayout)
  helpers.php                Global helper functions, autoloaded via composer.json "files"

routes/
  web.php                    All authenticated UI routes, grouped per module with prefix()/name()/middleware()
  api.php                    Bearer-token JSON endpoints (agent telemetry, driver mobile app) — NOT Sanctum, a raw api_token column check
  auth.php                   Breeze auth routes, required from web.php
  console.php                Artisan commands + Schedule:: definitions (visitor auto-checkout, leave carry-over forfeiture, transport expiry checks)

database/
  migrations/                Chronological; see "Migration conventions" below
  factories/                 Only exist for Transport-module models + User (older modules have no factories — build data manually in tests)
  seeders/                   Role/permission/module-access seeders per module + DatabaseSeeder orchestrating them

resources/views/
  {module}/                  Thin Blade wrapper views, usually just <livewire:.../> or a couple of lines — real UI lives in resources/views/livewire/{module}/
  livewire/{module}/         The actual Livewire component templates
  layouts/                   app.blade.php (Breeze default), erp.blade.php (the real app chrome/sidebar), guest.blade.php, navigation.blade.php
  components/                Shared Blade components (form/, global/, uac/, leave/)
  emails/                    Mail-notification Blade templates

config/gwl.php, config/gwcl.php   App-specific settings (see "Custom config" below) — NOT stock Laravel config
tests/Feature/{module}/      Feature tests, grouped per module; RefreshDatabase + Livewire::test() are the norm
tests/Unit/                  Currently empty/unused in practice — most coverage is Feature-level
```

## Key conventions

### Naming
- Tables and columns: `snake_case`. Models: singular `StudlyCase` matching Laravel defaults, with a few explicit `protected $table` overrides (`AgentReport` -> `agent_reports`... actually inferred correctly, but `IctAsset` -> `ict_assets`, `ModuleAccess` -> `module_access` are set explicitly since they don't pluralize cleanly).
- Route names: `{module}.{action}`, e.g. `staff.toggle-status`, `leave.export.approved.excel`, `uac.users.invite`. Route groups set the prefix and `name()` once per module in `web.php`.
- Permissions: `{module}.{action}` slugs, e.g. `staff.manage_departments`, `transport.view_reports`. Defined as rows in the `permissions` table (module column = one of `Permission::MODULES`), not as code constants beyond the module list itself.
- Middleware aliases used in routes: `auth`, `active` (EnsureUserIsActive), `module:{slug}`, `role:{role1,role2}`, `permission:{perm1,perm2}` (OR semantics — any one match passes).

### Authorization is layered, not single-source
1. Route middleware (`module:`, `role:`, `permission:`) — first line of defense.
2. Controller-level re-check via `App\Http\Controllers\Concerns\EnforcesModuleAccess` (some controllers only).
3. Livewire component re-check via `App\Livewire\Concerns\EnforcesModuleAccess` (a **separate, duplicate trait** — don't assume changing one changes both).
4. `super_admin` bypasses every check at every layer.
5. ICT Team users without admin/super_admin are location-scoped: in UAC to Head Office or one region (`User::ictScope()`, decided in `Services/Uac/RoleGrantPolicy`), in Assets/MDM by `region_id`. `isRegionScopedIct()` helpers still exist there as thin wrappers of `User::isScopedIct()`. In Assets, reading is wider than writing: list/dashboard queries use `scopeAssetsForViewing()`/`scopeReportsForViewing()` (Head Office ICT sees every region), while create/edit lookups use `scopeAssetsForActor()` (own region) and MDM stays on `MdmAccessGuard` — keep new list screens on the former and new write paths on the latter. Head Office is a *district* whose staff share the Head Office district's `region_id`, so never scope "Head Office" by region alone.

When adding a new protected screen, replicate this pattern rather than relying on route middleware alone — several existing controllers do the belt-and-suspenders check deliberately.

### Service layer pattern
Controllers and Livewire components stay thin and delegate to `app/Services/{Module}/*`. Follow the existing per-module split:
- `Services/Leave/` — `LeaveApprovalChainResolver`, `LeaveWorkflowService`, `LeaveBalanceService`, `LeaveEntitlementService`, `LeaveNotificationService`, `LeaveHrContactService`, `WorkingDaysCalculator` — one class per concern, not one god-service.
  - **Approval routing lives only in `LeaveApprovalChainResolver`** (`route()` for who, `canAct()`/`actionableRequests()` for who may act and the queue). Approvals, AllRequests, HomeSummaryService and ManagerDashboard all use it; don't re-derive a queue from `manager_id`. Rules and the routing table: `docs/06-module-leave.md`.
  - `LeaveWorkflowService::recommend()`/`finalDecision()` re-read the request `lockForUpdate` inside a transaction and authorize on that copy; keep any new action on that pattern. `manager_id`/`approved_by_id` are employee ids, `manager_user_id`/`chief_user_id` are user ids, `is_single_stage` marks a manager applying directly to the level above (its `manager_recommendation` is `Recommended` from submission).
  - Every leave email/in-app notice goes through `LeaveNotificationService` (email switches `gwl.leave_email_notifications_enabled` / `gwl.leave_hr_email_notifications_enabled`, both checked only there; mail failures are caught and logged). Head Office is a district, so HR scope comes from `location_type`, never `region_id` alone.
  - **Annual entitlement lives only in `LeaveEntitlementCalculator`** (pure: gross by grade and tenure, compulsory deduction, net; numbers in `config/gwl.php`). Never hard-code 31 (or 26/36) anywhere else: ask `AnnualEntitlementService` (storage in `leave_entitlements`, idempotent `generate()`, `recalculate()` on grade/hire-date/location/compulsory-days changes; days already used are never taken back) or `Employee::annual_leave_days`. Staff with **no grade** keep the flat 31 and no compulsory deduction by design; contract staff (Charwoman) have no leave (`LeaveWorkflowService::guardEligible()`). Compulsory leave (`compulsory_leave_periods`, `CompulsoryLeaveService`, Head Office HR / `admin` / `super_admin` only) comes off the *gross* entitlement and is never charged to `used_days`; years done by the old `compulsory_leave_deductions` screen read as 0 so nothing is deducted twice. Details: `docs/06-module-leave.md`.
- **Grade** (`employees.grade`, `App\Enums\StaffGrade`) fixes `employees.category` (`Employee::saving`); never store a category that contradicts a grade, and read the allowed grades only from the enum (form, import, filters, reports all do). Staff without a grade are normal legacy records, not errors.
- `Services/Hr/HrAnalyticsService` — HR workforce analytics (milestones, headcount, turnover, distribution, exits, grades). It owns its scope (super_admin/admin/hr_headoffice: all regions, hr_region: own region, anyone else 403; super_admin accounts never counted), all the date arithmetic (`$asOf`), and short caching (`gwl.hr_analytics_cache_seconds`); a Transfer exit reason is shown but never counted as an exit. Details: `docs/12-hr-analytics.md`.
- **Leave approval letters** (`docs/13-leave-letters.md`): wording, date formats and number-to-words live only in `LeaveLetterService` (never Blade); `leave_letters.snapshot` is frozen at generation and only reference_no, cc, signatory mode and the Christmas line change, before the first print (each PDF response counts as a print). Signatures go through `SignatureService` only: encrypted on the private `leave_signatures` disk, never logged, audited, emailed or listed, rendered only in the PDF, and only their owner can apply one; Global Admin/super_admin may only revoke. Acting approvers are resolved in `LeaveApprovalChainResolver` (`LeaveActingAssignmentService`) for the final-approver posts only.
- `Services/Letters/LetterWorkflowService` — routing/dispatch/remark logic.
- `Services/Assets/Mdm/` — Android Enterprise: `AndroidManagementGateway` (interface; `AndroidManagementClient` is the only class that touches Google), `PolicyService`, `EnrollmentService`, `DeviceService`, `CommandService`, `EventIntakeService`, `PubSubPushVerifier`, `MdmAccessGuard`. See "Android Enterprise (MDM)" below and `docs/assets/mdm.md`.
- `Services/Staff/DistrictEmployeeSync` — an employee's `location_type` follows their district's *name* (`Employee::locationTypeFor()`) and `region_id` follows its region, so editing a district on the locations screen re-saves its employees (through Eloquent, so the observer runs and Head Office-only roles are removed from anyone left outside Head Office). Never update `employees.district_id`/`location_type` with a raw query: it skips that.
- `Services/Staff/EmployeeDirectory` — the canonical place for role-scoped employee visibility queries (used by StaffController; UAC's employee search goes through `RoleGrantPolicy::employeesQueryFor()`). Its `applyFilters()` also takes `grade` (`none` = no grade yet) and `region_id`, which is how dashboard cards link into the staff list (`AllEmployees` keeps its filters in the URL).
- `Services/Staff/StaffReportService` — Staff Reports (renamed from "Staff Leave Reports"; the `staff.reports` route and `staff.view_reports` permission keep their names): leave and headcount charts, the category/grade breakdown and the Excel export. Its payload is cached, so it may only hold arrays and scalars (cache key prefix `staff_reports:v2:`).
- `Services/Uac/RoleGrantPolicy` / `RoleAssignmentService` — **all** role-management rules (tiers super_admin > Global Admin (`admin`) > ICT > others, ICT location scope, `roles.ict_assignable`/`is_protected`, role↔location fit, anti-escalation) live here; UacController, the roles screen, the users import and the Head Office transfer rule (`EmployeeObserver`) all call it. Editing a user diffs roles and only touches the ones the actor may manage. super_admin is invisible to everyone else via the `visibleTo($viewer)` scopes on `User`/`Employee`/`Role`/`AuditLog` (operational pickers keep `visibleInErp()`); start any list, count or export of audit rows with `AuditLog::visibleTo()`. Details: `docs/04-module-uac.md`.
- `Services/Commercial/` — the two report importers (`ReadingSummaryImportService`, `BillingSummaryImportService`, both on `ReportImportService`), reached through `CommercialImportService`; `ReportFileReader` (every sheet as a raw grid, cells found by LABEL never by coordinate), `LocationMatcher` (region/district text -> record, then `commercial_location_aliases`), `BatchLifecycleService` (void; recomputes imported/superseded), `BatchResolutionService` (resolve alias / link reader / re-match). Things that are easy to get wrong:
  - **The reports are pre-aggregated exports, not tables**, so `DataImportService`/`RawRowsImport` are deliberately not used (`RawRowsImport` only reads the first sheet). A file whose rows do not add up to its own totals is **blocked**; an unmatched reader/district only **warns** and is resolved afterwards.
  - Rows are immutable snapshots tied to a batch. Read them through `scopeEffective()` on the stat/route/band/strength models (latest non-voided batch wins), never straight off the table; `status` imported/superseded is only a label recomputed by `BatchLifecycleService::refreshStatuses()`. Compare dates with `DATE()`/`whereDate`: the `date` cast is stored as `2026-06-01 00:00:00` on SQLite.
  - **Verified against the real exports** (June-Sep 2025 reading, Jun-Aug 2026 new-service billing): the reading file's `Document map` sheet declares a used range out to column XFC and exhausts memory if loaded, so `ReportFileReader::readSheets()` lists sheet names first and never loads it; the filter block is ONE multi-line cell (`ReportFileReader::labelValue()` splits lines); reader sheets are named `Sheet2`.. and use two-row headers (`Verified`/`Strength`, `Read` over `#`/`%`); the grand-total sheet has overall Read/Skipped/Visited only, no month rows (a per-month grand sheet is also reconciled month by month).
  - **Reading analytics (Phase 2)** live in `ReadingAnalyticsService` (R1-R13 as pure passes over the already-scoped effective rows; the Livewire component owns scoping and filters, like `AssetSummaryService`). Rates are percentages and `null` on a zero denominator; the current calendar month is "in progress" and is excluded from movement/inactive/workload/consistency/outliers/scorecard. Reader-level screens (`Reading` tabs Readers/Exceptions/Scorecard, `ReaderDetail`) are individual staff performance: `commercial.view_reader_performance`, checked per tab and per reader (403 for another region's reader). Coverage uses the region-wide strength, so it is hidden under a district filter.
  - **Billing analytics (Phase 3)** live in `BillingAnalyticsService`, one snapshot at a time (`snapshots()` = newest non-voided batch per region/segment/period; never sum snapshots: balances roll forward). Every district/snapshot figure is a sum of route figures with the ratio recomputed from the sums, never an average of route ratios. `customers_count` is stored but never used or shown (its meaning is unconfirmed). A multi-month snapshot is usable on its own but excluded from compare/trend; negative balances are only ever described as "treated as customer credits (to be confirmed)". The `Billing` screen falls back to the default snapshot for an id outside the user's regions instead of 403ing.
  - **Combined analyses and exports (Phase 4):** `CommercialInsightsService` (C1 district scorecard, C2 estimation vs skip rate, R14 month-to-date pace, C3 summary) reuses the two analytics services and never re-implements their maths. Billing is a customer segment while reading covers everyone, and reading by district is the reader's HOME district: any screen or export that sets them side by side carries those notes, projections say "indicative", correlations appear only from 6 pairs and are never "causal", and snapshot times are UPLOAD times. Exports go through `CommercialReportData` (same scoped queries as the screens, via `ScopesCommercialByActor`) -> `CommercialExportService` (titled tables) -> `CommercialReportExport` (Excel; `ReportSheet` is a string value binder so reader names / route codes starting `= + - @` stay text) or the Dompdf view; the one route `commercial.export` needs `export_reports` plus the report's own permission (readers: `view_reader_performance`), is audited, and is capped by `commercial_export_max_rows`.
  - **Settings and reminders (Phase 5):** the editable targets and thresholds are overrides of `config/gwl.php` stored in `commercial_settings` and laid over the config at boot by `CommercialSettings::applyToConfig()` (`AppServiceProvider`), so analyses and exports keep reading `config('gwl.commercial_*')` and never the table; a value saved equal to its default is stored as no override, and the edit screen needs `commercial.manage_settings` (super_admin by default). Upload reminders (`UploadReminderService`, `commercial:remind-uploads`, scheduled only while the module is on and needing the scheduler running) tell the officers of a region, in-app, when its latest non-voided upload of a type is older than the configured days, at most once per repeat interval.
  - **Phase 5b:** the defaults come from a pristine copy of `config('gwl')` kept by the `CommercialSettings` singleton (never a `require` of the config file), so `config:cache` cannot change them; after editing `.env` on a server run `config:cache` again. Users without `view_reader_performance` see no reading figures for a home district with fewer than `commercial_min_readers_for_district_figures` distinct active readers (decided in `ScopesCommercialByActor::hideSmallGroups()`, applied in `CommercialInsightsService::districtScorecard()` and `CommercialReportData::readingTrend()`, so screens and exports agree). PDFs have their own row cap (`commercial_export_pdf_max_rows`), and the Settings screen previews how many routes the typed exception thresholds would flag (`BillingAnalyticsService::exceptions($routes, $thresholds)`).
  - The source's percentages, `Unvisited` and `Collection Ratio` are never stored (they are measured against the whole region); recompute from counts. The `00000` System Administrator account is kept (`match_status = system_account`) but excluded via `CommercialReadingStat::scopeReaders()`.
  - Region scope is `Livewire\Commercial\Concerns\ScopesCommercialByActor` (a third, separate scoping trait: super_admin/Global Admin/Head Office see all, everyone else their own `employee->region_id`). Every `BatchShow` action re-checks permission AND region.
  - Tests build small synthetic workbooks with `Tests\Support\Commercial\ReportWorkbooks` (never commit real exports; they hold staff names and customer routes). PhpSpreadsheet's `fromArray()` needs `strictNullComparison = true` or it drops zeros.
- `Services/Transport/TransportService`, `TransportNotificationService`.
- `Services/Import/DataImportService` — shared xlsx import/preview/validate pipeline for both the UAC and Staff import screens (see `ImportController`).
- Flat `Services/ReportsService` — date-range resolution + payload building for transport reports (PDF/Excel).

If you're adding meaningful business logic, put it in a new or existing service class, not directly in a controller/Livewire method.

### Android Enterprise (MDM) — things that are easy to get wrong
- **Feature flag.** `config('gwl.mdm_enabled')` (env `GWL_MDM_ENABLED`, default false, true in `phpunit.xml`) is checked where the routes are registered (`web.php`, like Credit Union), in the `routes/console.php` schedule, in the sidebar `can` closures, on Livewire `mount()`, and at the top of every `mdm:*` command. Routes register at boot, so a "flag off" test must rebuild the app with an env override (see `AccessAndFeatureFlagTest`).
- **Two separate route groups on purpose.** MDM routes live in their own `assets/mdm` group (`role:super_admin,admin,ict_team`), not inside the main Assets group, because `admin` may use MDM but nothing else in Assets and a nested group cannot loosen its parent's `role:` check. `ErpNavigation::assetsLandingRoute()` sends `admin`'s Assets tile to the MDM dashboard.
- **The webhook is `POST /webhooks/android-management`** in `web.php` with `->withoutMiddleware('web')` (no session, no CSRF) and `throttle:mdm-webhook`. It authenticates by Google OIDC + secret `?token=` and must never call Google or do device work; it stores an `mdm_events` row and dispatches `ProcessAndroidNotification`. Do not reuse the `api_token` scheme.
- **Region scope for MDM is `MdmAccessGuard`** (the service-level twin of the Livewire `ScopesAssetsByActor` trait; jobs have no `Auth::user()`). `ScopingParityTest` pins the two together. Resolve every device/asset id through it; never `MdmDevice::find($id)` from a client value. Commands re-check scope and permission again inside the queued job.
- **A queue worker and a per-minute scheduler are required** — MDM is the first thing that needs either. Tests use `sync`, so use `Queue::fake()` when you need to assert dispatch.
- **Tests never reach Google:** bind `Tests\Support\Mdm\FakeAndroidManagementGateway` / `FakeIdTokenVerifier` (see `Tests/Feature/Assets/Mdm/Concerns/BuildsMdmFixtures`). `AndroidManagementClientTest` drives the real client through a Guzzle mock (record in the *handler*, not a middleware: Guzzle runs middleware in insertion order, so an earlier one never sees Google's auth header).
- **Google enum values are SHOUTING_SNAKE_CASE; `Str::headline()` spells them out letter by letter.** Use `App\Services\Assets\Mdm\Labels`.
- Wipe is `devices.delete`, not `issueCommand` (AMAPI also has a `WIPE` command type; deliberately unused). `mdm_device_commands.payload` is encrypted and secrets (the reset passcode) are blanked after delivery; never put them in audit metadata.
- The seeded "GWL Standard Phone" policy exists in every test database (a migration creates it) — don't assert global policy counts.

### Audit logging
Any mutating action a human should be able to trace back later calls `AuditLog::record(...)` (static helper on the model) or the `App\Support\Audit::log(...)` wrapper — both write to `audit_logs`. Follow this pattern for new create/update/delete/export endpoints; it's checked for in several existing tests indirectly via the UAC audit log screen.

### Migration conventions
- Never edit an existing migration once merged — add a new `add_x_to_y_table` / `create_x_table` migration instead. The history is full of these (`add_full_name_to_users_table`, `add_must_change_password_to_users_table`, etc.).
- New/altering migrations defensively check `Schema::hasTable()` / `Schema::hasColumn()` before acting, so they're safe to re-run against a partially-migrated DB.
- Foreign keys are explicit about delete behavior: `restrictOnDelete()` for people/lookup data that shouldn't silently vanish, `cascadeOnDelete()` for child/detail rows, `nullOnDelete()` for optional links.
- Granting a role access to a new module, or adding a new permission, is done by data-seeding **inside a migration** (`DB::table('permissions')->updateOrInsert(...)`, `DB::table('module_access')->updateOrInsert(...)`), not only in `database/seeders`. Follow this pattern so a fresh `migrate` and an existing `migrate` on a running DB end up in the same state.
- UUIDs: only the Transport-module models (`Vehicle`, `MileageLog`, `VehicleIssue`, `MaintenanceRecord`, `VehicleExpense`, `VehicleAssignmentHistory`) use a `uuid` column via the `App\Models\Concerns\HasUuid` trait, and `Vehicle` uses `uuid` as its route-key (`getRouteKeyName()`). Everything else uses the numeric `id`.
- Soft deletes: only `Vehicle` uses `SoftDeletes`. Nothing else does — don't assume `deleted_at` exists elsewhere.

### Custom config
`config/gwl.php` and `config/gwcl.php` hold app-specific env-driven settings (auto-checkout time, leave carry-over expiry window, import failure threshold, the `mdm_*` Android Enterprise settings, etc.) — these are **not** stock Laravel config files, they were added for this app. `gwcl.php` currently exists mostly as a legacy/fallback alias for one `gwl.php` key; prefer adding new settings to `gwl.php` and reading them as `config('gwl.your_key')`.

## Testing

- Plain PHPUnit, not Pest. Test suites are `tests/Unit` and `tests/Feature` (see `phpunit.xml`); in practice almost all coverage is under `tests/Feature/{Module}/`.
- Tests run against an **in-memory SQLite DB** (`phpunit.xml` sets `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`), with mail/queue/cache/session drivers swapped to array/sync equivalents. `BCRYPT_ROUNDS` is dropped to 4 for speed.
- `RefreshDatabase` is the standard trait on feature tests that touch the DB.
- Livewire components are tested with `Livewire::test(ComponentClass::class)->set(...)->assertSee(...)`, not through raw HTTP requests to their host route.
- Most tests build their own fixture data inline (roles, module_access, regions/districts/departments/job titles, employees) rather than relying on factories — factories only exist for the newer Transport module models and `User`. Follow the existing inline-builder pattern (see `tests/Feature/Staff/EmployeeDeactivationTest.php` for a representative example: `createStaffUser()` / `createEmployee()` helper methods) when adding tests for older modules, and use factories when adding tests for Transport.
- `Notification::fake()` is used liberally to avoid hitting real mail during tests that trigger user creation/invites.

Run tests:
```bash
php artisan test                 # full suite, colorized
php artisan test --filter=Name   # single test/class
composer run test                # same as above, clears config cache first
```

## Running migrations / local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate              # creates database/database.sqlite tables (touch the file first if it doesn't exist)
php artisan db:seed              # runs DatabaseSeeder: roles -> permissions -> module_access -> per-module role/permission seeders -> TransportSeeder -> HolidaySeeder -> SuperAdminSeeder
npm install
npm run build
```

Or the one-shot composer script: `composer run setup`.

Local development (serves app + queue listener + log tail + Vite, all concurrently):
```bash
composer run dev
```

Other useful commands:
```bash
php artisan route:list --except-vendor    # inspect the full route table
php artisan migrate:fresh --seed          # nuke and rebuild the local DB
php artisan gwcl:auto-checkout-visitors   # manually run the visitor auto-checkout job
php artisan leave:forfeit-expired-carry-over --dry-run   # preview annual carry-over forfeiture (drop --dry-run to apply)
php artisan schedule:work                 # run the scheduler locally (visitor checkout, carry-over forfeiture, transport expiry checks)
```

Seeding order matters: `DatabaseSeeder` runs `RoleSeeder` and `PermissionSeeder` before any module-specific role/permission seeder, and `SuperAdminSeeder` last. Two seeders (`LeavePermissionsSeeder`, `LeaveRolePermissionSeeder`) exist in `database/seeders/` but are **not** called by `DatabaseSeeder` — they're legacy/superseded by the migration-embedded seeding described above; don't assume they run.

## Known rough edges (verified against current code)

- `App\Http\Controllers\Leave\LeaveRequestController` references a `leave.access` middleware alias and a singular `$user->hasRole()` method that don't exist elsewhere in the codebase (`User` only defines `hasRoles()`, plural), and the controller isn't referenced anywhere in `routes/web.php`. Treat it as dead/legacy code, not a pattern to copy.
- Two separate `EnforcesModuleAccess` traits exist (`Http/Controllers/Concerns` and `Livewire/Concerns`) with identical intent — they are not shared, so a fix to module-access logic needs to be applied in both places.
- `UacController` has some commented-out legacy code blocks left in place (e.g. in `store()`) — safe to ignore/clean up but don't be confused by it when reading the file.
- `APP_DOCUMENTATION.md` / `docs/` predate the Assets and Transport modules; don't treat their silence on those modules as "not implemented."
